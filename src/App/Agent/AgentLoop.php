<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Closure;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\Context\ContextCompactor;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Tool\ToolCallValidator;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\PromptCache;
use OpenCCK\Domain\Agent\ProviderProfile;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Agent\TranscriptEntry;
use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\LlmCallRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;


use function Mercurius\I18n\t;

/**
 * The agent loop: model → tool calls → results → model, until an answer or a limit. One iteration is a chain of
 * phases over one RunState; every stop has a named reason. State is saved after each step, so a run continues
 * after a restart.
 *
 * spec: BRIEF.md#запуск-и-цикл, BRIEF.md#лимиты, BRIEF.md#устойчивость
 * ref: references/hermes-agent/agent/conversation_loop.py@e96221f:L1648-L1807 (_run_conversation_turn) — iteration as
 *      phases with a verdict; we add a token budget reserved before each call and resume interrupted runs
 * ref: references/hermes-agent/agent/turn_tool_round.py@e96221f:L49-L238 (run_tool_round) — validate, persist before
 *      execution, execute, guardrail
 */
final class AgentLoop {
    /** Output cap of the closing summary after a limit. */
    /** Seconds for the summary call after a limit: past the deadline it still gets its own short time. */
    /** Continuations of an answer cut off by the output limit. */
    private const MAX_CONTINUATIONS = 4;
    /** Retries of an empty answer. */
    private const MAX_EMPTY_RETRIES = 3;
    /** an empty answer whose input cost this much (USD) is retried once: each retry bills the whole input again */
    private const COSTLY_EMPTY_USD = 0.25;
    public const TEXT_ACTION_NOTE = 'agent.agent_loop.text_action';
    /** how the system note of done actions begins (now and before), in both languages */
    private const ACTION_MARKERS = ['[Сделано в этом запуске', '[Done in this run', '[Служебная заметка системы', '[System note'];
    private const SELF_INFO = 'self_info';
    /**
     * Words of an answer that says the agent cannot do something; lower case. Wide on purpose: a false alarm costs one
     * look in self_info a run, a miss sends the owner a wrong "no" (doorotar 2026-11-19: "нет инструмента", "нельзя").
     */
    private const DENIAL_MARKERS = [
        'не вижу такой возможности', 'нет такой возможности', 'такой возможности нет', 'нет возможности',
        'нет такой функции', 'такой функции нет', 'нет инструмента', 'нет такого инструмента', 'не умею',
        'не поддерживаю', 'не могу', 'нельзя', 'невозможно',
        "can't", 'cannot', 'not able to', 'unable to', 'no such feature', "don't have the ability", 'not possible',
    ];
    public const CONTINUE_NOTE = 'agent.agent_loop.continue_note';
    public const EMPTY_NOTE = 'agent.agent_loop.empty_note';
    private const SUMMARY_SECONDS = 60.0;
    private const SUMMARY_TOKENS = 1000;
    /** Saved as the answer of a stopped run: the model sees the task as closed, not as waiting. */
    public const STOPPED_NOTE = 'agent.agent_loop.stopped_note';

    private readonly ToolCallValidator $validator;
    /** @var (Closure(int): ?ParticipantPolicy)|null a ceiling for a run's policy, read before every model call */
    private ?Closure $cap = null;
    /** which models get the rule to act through tools (AGENT_TOOL_USE_GUIDANCE) */
    private ToolUseGuidance $toolUse;
    private readonly ToolRound $round;
    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param positive-int $maxTokens output cap of one model call
     * @param positive-int $historyLimit messages of earlier runs sent to the model
     * @param (Closure(): float)|null $clock seconds, for tests
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly LlmCallRepository $calls,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly int $maxTokens,
        private readonly ?string $reasoningEffort,
        private readonly int $historyLimit,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Closure $clock = null,
        private readonly ?ApprovalRepository $approvals = null,
        private readonly ?ApprovalGate $gate = null,
        private readonly ?ContextCompactor $compactor = null,
        private readonly AgentEvents $events = new NullEvents(),
        private readonly ?FallbackModels $fallback = null,
        private readonly ?SpendGuard $spend = null,
        /** how much of the window tool results may take; null — by the default window of 131072 tokens */
        ?\OpenCCK\App\Agent\Tool\ResultBudget $budget = null,
    ) {
        $this->budget = $budget ?? \OpenCCK\App\Agent\Tool\ResultBudget::forWindow(131_072);
        $this->toolUse = new ToolUseGuidance();
        $this->validator = new ToolCallValidator($registry);
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->round = new ToolRound($runs, $transcript, $executor, $this->budget, $this->clock, $logger, $approvals, $gate, $events);
    }

    private readonly \OpenCCK\App\Agent\Tool\ResultBudget $budget;

    /** Runs until the run ends; on shutdown returns the run still running, to resume after restart. */
    public function run(Run $run, ParticipantPolicy $policy, Principal $principal, RunObserver $observer, Cancellation $cancellation): Run {
        \OpenCCK\Infrastructure\Logger\RunContext::set($run->id);
        $meta = $this->conversations->meta($run->conversationId);
        $state = new RunState(
            $run,
            $policy,
            $principal,
            $observer,
            $cancellation,
            $meta->systemPrompt ?? SystemPrompt::text(),
            [],
            ($this->clock)(),
        );
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a resumed run goes on with the model it switched to
        $state->model = $run->fallbackModel;
        $state->switched = $run->fallbackModel !== null;
        $this->loadContext($state);
        $this->events->runStarted($run->id, (string) ModelRef::parse($run->model));

        try {
            // finish() is inside: /stop during the closing summary call must stop the run, not escape.
            $reason = $this->resumePending($state);
            if (!$state->paused) {
                $reason ??= $this->iterate($state);
            }
            if ($state->paused) {
                return $this->pause($state);
            }
            return $this->finish($state, $reason ?? StopReason::InternalError);
        } catch (CancelledException $e) {
            if (self::isShutdown($e)) {
                $this->flushTime($state);
                $this->logger->info('Run paused by shutdown', ['run' => $run->id]);
                return $this->runs->find($run->id) ?? $run;
            }
            return $this->stop($state);
        }
    }

    /** spec: BRIEF.md#агентский-цикл-и-харнесс — AGENT_TOOL_USE_GUIDANCE */
    public function useToolUseGuidance(ToolUseGuidance $guidance): void {
        $this->toolUse = $guidance;
    }

    /**
     * A ceiling for the policy of a run, read before every model call: someone with fewer rights may have joined the
     * conversation, and their words are delivered next.
     *
     * @param Closure(int): ?ParticipantPolicy $cap by run
     */
    public function useCap(Closure $cap): void {
        $this->cap = $cap;
    }

    /**
     * Turns until the run ends: each turn is prepared, the model is called, and its answer either ends the run or
     * leads to a round of tools. Every phase says whether the run goes on.
     *
     * @return StopReason|null null when the run paused for an approval
     */
    private function iterate(RunState $state): ?StopReason {
        while (true) {
            $step = $this->prepareTurn($state);
            if ($step === null) {
                $called = $this->callPhase($state);
                $step = $called instanceof Step ? $called : ($called->hasToolCalls() ? $this->toolPhase($state, $called) : $this->answerPhase($state, $called));
            }
            if (!$step->goesOn) {
                return $step->reason;
            }
        }
    }

    /** The ceiling of a group, steering messages, limits, spend and compaction before the call; null — go on. */
    private function prepareTurn(RunState $state): ?Step {
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — in a group the rights of the weakest active participant
        $ceiling = $this->cap === null ? null : ($this->cap)($state->run->id);
        if ($ceiling !== null) {
            $state->policy = $state->policy->narrowedBy($ceiling, keepApproval: $state->principal->isOwner());
        }
        $this->deliverInbox($state);
        $reason = $this->checkLimits($state);
        if ($reason !== null) {
            return Step::stop($reason);
        }
        // Before compaction: its summary call costs money too.
        $over = $this->spend?->exceeded($state->run->principalId, $state->policy->dailySpendUsd);
        if ($over !== null) {
            $this->logger->warning('Spend cap reached; the run stops', ['run' => $state->run->id, 'reason' => $over]);
            return Step::stop(StopReason::SpendCap);
        }
        if ($this->compactor?->ensure($state->run, $state->systemPrompt, $this->historyLimit, $this->estimate($state), cancellation: $state->cancellation) === true) {
            $this->loadContext($state);
        }
        return null;
    }

    /** The model call with its budget; a failed call is repeated, compacted, switched to another model or stops the run. */
    private function callPhase(RunState $state): ChatResponse|Step {
        $reserved = $this->reserve($state);
        if ($reserved === null) {
            return Step::stop(StopReason::TokenBudget);
        }
        $left = ($state->run->limits->runTimeout * 1000 - $this->elapsedMs($state)) / 1000;
        $response = $this->callModel($state, $this->request($state, $this->registry->definitions($state->policy), $this->maxTokens, null, $this->tail($state)), $reserved, max(1.0, $left));
        if ($response === null) {
            if ($this->elapsedMs($state) >= $state->run->limits->runTimeout * 1000) {
                // The deadline cut the call off: a limit, with a summary, not a failure of the model.
                return Step::stop(StopReason::Deadline);
            }
            // ref: references/hermes-agent/agent/turn_truncation.py@e96221fa:L313-L345 — a stream that broke after
            //      it began is a stub, not a verdict: nothing was saved yet, so the call is made once more
            // Before the first event the client already retried: only a break after it is repeated here.
            if ($state->streamBegan && in_array($state->lastError, [ErrorKind::Server, ErrorKind::Network, ErrorKind::Timeout], true) && !$state->streamRetried) {
                $state->streamRetried = true;
                return Step::next();
            }
            // spec: BRIEF.md#контекст — the provider proved the context too long: compact once and ask again
            if ($state->lastError === ErrorKind::ContextLength && !$state->overflowRetried && $this->compactor !== null) {
                $state->overflowRetried = true;
                $this->compactor->ensure($state->run, $state->systemPrompt, $this->historyLimit, $this->estimate($state), force: true, cancellation: $state->cancellation);
                $this->loadContext($state);
                return Step::next();
            }
            // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — one switch per run to another model
            // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — not for a content filter: it judged the request, the
            //       model works; another model would only be asked to say the same
            if ($state->lastError !== ErrorKind::ContentFilter && $this->switchModel($state)) {
                return Step::next();
            }
            return Step::stop(StopReason::ModelFailed);
        }
        $state->overflowRetried = false;
        $state->streamRetried = false;
        return $response;
    }

    /** An answer without tools: continued when cut, asked again when empty or when it reports an action in words. */
    private function answerPhase(RunState $state, ChatResponse $response): Step {
        // ref: references/hermes-agent/agent/turn_truncation.py@e96221fa:L313-L379 (_continue_text) — an answer
        //      cut off by the output limit is continued and stitched, up to 4 times
        // A cut answer with no text is an empty one: the output went to reasoning, a continuation would repeat it.
        if ($response->finishReason === FinishReason::Length && trim($response->text) !== '' && $state->continuations < self::MAX_CONTINUATIONS) {
            $state->continuations++;
            $state->partial .= $response->text;
            return Step::next();
        }
        $text = $state->partial . $response->text;
        // ref: references/hermes-agent/agent/turn_empty_response.py@e96221fa:L105-L155 (_retry_empty) — an empty
        //      answer is asked again with a hint, up to 3 times
        if (trim($text) === '' && $this->retryEmpty($state, $response)) {
            return Step::next();
        }
        $state->emptyStreak = [];
        $state->lessReasoning = false;
        // spec: BRIEF.md#агентский-цикл-и-харнесс — an action is done only by a tool call: an answer that reports one in
        //      words (it copies the system note of earlier runs) is shown back once with a reminder
        // ref: references/hermes-agent/agent/prompt_builder.py@e96221fa:L420-L440 — hermes warns models that "claim
        //      completion without tool calls" in the prompt; here the loop also catches the copied note
        // a run that did act may repeat its own system note: it is reminded only when it did nothing
        if (!$state->textActionRetried && self::reportsAnAction($text) && $this->actionsNote($state->run->id) === '') {
            $state->textActionRetried = true;
            $state->textAction = $text;
            $state->partial = '';
            $this->logger->notice('An action was written as text, not called', ['run' => $state->run->id]);
            return Step::next();
        }
        $state->textAction = null;
        // spec: BRIEF.md#telegram-группы-каналы-и-темы — [SILENT] is for a turn with nothing to say: a run that acted (a
        //      skill set up, a file written) tells what it did and what failed, once asked
        if (!$state->silenceRetried && str_starts_with(ltrim($text), '[SILENT]') && $this->actionsNote($state->run->id) !== '') {
            $state->silenceRetried = true;
            $state->silenceAfterActions = true;
            $state->partial = '';
            $this->logger->notice('A silent answer after actions: asked to report', ['run' => $state->run->id]);
            return Step::next();
        }
        $state->silenceAfterActions = false;
        // spec: BRIEF.md#знания-агента-о-себе — "no such ability" about itself only after self_info: a weak model
        //      answered "I cannot pass ownership" without looking, while /owner exists
        if (!$state->selfCheckRetried && self::deniesAbility($text) && $this->registry->get(self::SELF_INFO) !== null && !$this->called($state->run->id, self::SELF_INFO)) {
            $state->selfCheckRetried = true;
            $state->selfCheck = $text;
            $state->partial = '';
            $this->logger->notice('An ability was denied without self_info: asked to check', ['run' => $state->run->id]);
            return Step::next();
        }
        $state->selfCheck = null;
        $state->partial = '';
        $state->finalText = $text;
        $this->saveAnswer($state, $text);
        return Step::stop(StopReason::Answered);
    }

    /** An answer with tool calls: validated, saved, run as a round; three turns without one valid call stop the run. */
    private function toolPhase(RunState $state, ChatResponse $response): Step {
        // A step with tools ends whatever answer was being continued or retried: the next answer starts clean.
        if ($state->partial !== '' || $state->emptyRetries > 0) {
            $this->logger->info('Model turned to tools mid-answer; the fragment is dropped', ['run' => $state->run->id]);
        }
        $state->partial = '';
        $state->continuations = 0;
        $state->emptyRetries = 0;
        $state->emptyStreak = [];
        $state->lessReasoning = false;

        $outcome = $this->validator->validate($response->toolCalls, $state->policy, $this->transcript->callIds($state->run->id));
        if ($outcome->truncated) {
            // hermes refuses cut-off arguments at once (turn_tool_validation.py L216-L237); we repeat the request once.
            if ($state->truncatedRetried) {
                return Step::stop(StopReason::Truncated);
            }
            $state->truncatedRetried = true;
            return Step::next();
        }
        $state->truncatedRetried = false;

        $calls = $this->round->prepare($state, $outcome->calls);
        if (!$this->round->persist($state, $response->text, $calls)) {
            return Step::stop(StopReason::PersistFailed);
        }
        if (trim($response->text) !== '') {
            $state->observer->commentary($state->run, $response->text);
        }
        $reason = $this->round->execute($state, $calls);
        if ($reason !== null || $state->paused) {
            return Step::stop($reason);
        }

        $state->invalidTurns = $outcome->hasValid() ? 0 : $state->invalidTurns + 1;
        if ($state->invalidTurns >= 3) {
            return Step::stop(StopReason::InvalidCalls);
        }
        $this->flushTime($state);
        return Step::next();
    }

    /** Calls saved before a restart but never finished run first, so the transcript stays a valid sequence. */
    private function resumePending(RunState $state): ?StopReason {
        $pending = $this->transcript->pendingCalls($state->run->id);
        if ($pending === []) {
            return null;
        }
        $calls = array_map(function (StoredToolCall $stored) use ($state): ValidatedCall {
            $call = new ToolCall($stored->callId, $stored->name, $stored->argumentsJson);
            $tool = $this->registry->allows($state->policy, $stored->name) ? $this->registry->get($stored->name) : null;
            $error = $tool === null || $stored->args === null ? t('agent.agent_loop.instrument_nedostupen_posle_perezapuska') : null;
            return new ValidatedCall($call, $tool, $stored->args, $error, $stored->danger);
        }, $pending);
        return $this->round->execute($state, $calls);
    }

    /**
     * Steering messages go in only here, between iterations, after the results of the last answer — so a
     * call and its result are never split.
     *
     * ref: references/hermes-agent/agent/turn_iteration_prep.py@e96221f:L175-L181, L284-L295 — /steer delivered after the
     *      last tool result
     */
    private function deliverInbox(RunState $state): void {
        foreach ($this->transcript->consumeInbox($state->run->id) as $text) {
            $this->transcript->appendUser($state->run->conversationId, $state->run->id, $text);
        }
    }

    private function checkLimits(RunState $state): ?StopReason {
        $run = $state->run;
        if ($run->turns >= $run->limits->maxTurns) {
            return StopReason::TurnLimit;
        }
        if ($this->elapsedMs($state) >= $run->limits->runTimeout * 1000) {
            return StopReason::Deadline;
        }
        return null;
    }

    /**
     * spec: BRIEF.md#лимиты — the worst case is reserved before the call, then replaced with the actual spend
     *
     * @return int|null tokens reserved, null when the budget is exhausted
     */
    private function reserve(RunState $state): ?int {
        $reserved = $this->estimate($state) + intdiv(mb_strlen($state->partial), 4) + $this->maxTokens;
        return $this->runs->reserve($state->run->id, $reserved) ? $reserved : null;
    }

    /**
     * Input tokens of the next call: the prompt tokens of the last call plus what was added since, or, without that
     * anchor (first call, after a compaction), characters of the whole request / 4.
     */
    private function estimate(RunState $state): int {
        $messages = $this->transcript->runMessages($state->run->id);
        if ($state->run->promptAnchor > 0) {
            return $state->run->promptAnchor + intdiv(self::chars(array_slice($messages, $state->messagesAtCall)), 4);
        }
        return intdiv(mb_strlen($state->systemPrompt . ($state->summary ?? '')) + self::chars($state->history) + self::chars($this->contextMessages($state)), 4);
    }

    /** Summary and history as of now: after a compaction they change. */
    private function loadContext(RunState $state): void {
        $compaction = $this->conversations->compaction($state->run->conversationId);
        $state->summary = $this->compactor?->summaryMessage($state->run->conversationId);
        $state->uptoId = $compaction->uptoId;
        $state->history = array_map(
            static fn(TranscriptEntry $e): Message => $e->message,
            $this->transcript->historyEntries($state->run->conversationId, $state->run->id, $this->historyLimit, $compaction->uptoId),
        );
        $state->run = $this->runs->find($state->run->id) ?? $state->run;
    }

    /** @return list<Message> messages of the run the model gets: those after the summary */
    private function contextMessages(RunState $state): array {
        return array_map(static fn(TranscriptEntry $e): Message => $e->message, $this->transcript->runEntries($state->run->id, $state->uptoId));
    }

    /**
     * What this call adds after the history without saving it: the fragment of a cut-off answer with a request to go
     * on, or a hint after an empty answer.
     *
     * @return list<Message>
     */
    private function tail(RunState $state): array {
        if ($state->textAction !== null) {
            return [Message::assistant($state->textAction), Message::user(\Mercurius\I18n\t(self::TEXT_ACTION_NOTE))];
        }
        if ($state->selfCheck !== null) {
            return [Message::assistant($state->selfCheck), Message::user(\Mercurius\I18n\t('agent.agent_loop.self_check'))];
        }
        if ($state->silenceAfterActions) {
            return [Message::assistant('[SILENT]'), Message::user(\Mercurius\I18n\t('agent.agent_loop.silent_after_actions'))];
        }
        if ($state->partial !== '') {
            return [Message::assistant($state->partial), Message::user(\Mercurius\I18n\t(self::CONTINUE_NOTE))];
        }
        return $state->emptyRetries > 0 ? [Message::user(\Mercurius\I18n\t(self::EMPTY_NOTE))] : [];
    }

    /**
     * @param list<\Mercurius\Llm\ToolDefinition> $tools
     * @param positive-int $maxTokens
     * @param list<Message> $tail messages for this call only, not saved
     */
    private function request(RunState $state, array $tools, int $maxTokens, ?string $closingPrompt = null, array $tail = []): ChatRequest {
        $summary = $state->summary === null ? [] : [Message::user($state->summary)];
        $model = $state->model ?? $state->run->model;
        // spec: BRIEF.md#агентский-цикл-и-харнесс — a model of a family that answers in prose is told to act through tools
        $system = $tools !== [] && $this->toolUse->applies($model) ? $state->systemPrompt . "\n\n" . \Mercurius\I18n\t('agent.tool_use_guidance') : $state->systemPrompt;
        $messages = [Message::system($system), ...$summary, ...$state->history, ...$this->contextMessages($state), ...$tail];
        $state->messagesAtCall = count($this->transcript->runMessages($state->run->id));
        if ($closingPrompt !== null) {
            $messages[] = Message::user($closingPrompt);
        }
        $effort = $state->lessReasoning ? 'low' : $this->reasoningEffort;
        return new ChatRequest($model, $messages, $tools, $maxTokens, reasoningEffort: $effort, extra: self::cacheKey($model, $state->run->conversationId));
    }

    /**
     * Whether an empty answer is asked again. Each retry bills the whole input once more, so a costly one is retried
     * once, and two answers in a row with no output at all from the same model are not retried: the model switches if
     * it can. An answer that went to reasoning is asked again with less of it.
     *
     * ref: references/hermes-agent/agent/empty_response_guard.py@e96221fa:L1-L30, L157-L237 (deterministic_empty,
     *      empty_retry_budget) — the signature of model, provider and finish, zero output and reasoning proven by usage,
     *      a budget of 1 over $0.25; here the cost is the one the provider reported, and a reasoning-only empty answer
     *      lowers the effort of the retry instead of being left to the prefill path
     */
    private function retryEmpty(RunState $state, \Mercurius\Llm\ChatResponse $response): bool {
        $usage = $response->usage;
        $nothing = $usage->inputTokens > 0 && $usage->outputTokens + $usage->reasoningTokens === 0;
        $state->emptyStreak[] = $nothing ? $response->model . '|' . $response->finishReason->value : null;
        $state->lessReasoning = $usage->reasoningTokens > 0;
        $streak = $state->emptyStreak;
        if (count($streak) >= 2 && !in_array(null, $streak, true) && count(array_unique($streak)) === 1) {
            $this->logger->notice('The same empty answer twice: not asked again', ['run' => $state->run->id, 'model' => $response->model]);
            if ($this->switchModel($state)) {
                $state->emptyRetries = 0;
                $state->emptyStreak = [];
                return true;
            }
            return false;
        }
        $budget = ($usage->costUsd ?? 0.0) >= self::COSTLY_EMPTY_USD ? 1 : self::MAX_EMPTY_RETRIES;
        if ($state->emptyRetries >= $budget) {
            return false;
        }
        $state->emptyRetries++;
        return true;
    }

    /** One switch per run to another model; false when there is none. */
    private function switchModel(RunState $state): bool {
        if ($this->fallback === null || $state->switched) {
            return false;
        }
        $next = $this->fallback->next($state->model ?? $state->run->model, $state->run->conversationId, $state->run->id);
        if ($next === null) {
            return false;
        }
        $state->switched = true;
        $state->model = $next;
        $this->runs->switchModel($state->run->id, $next);
        return true;
    }

    /**
     * OpenAI routes requests with the same key to the same cache: the conversation names its prefix.
     *
     * ref: references/hermes-agent/agent/auxiliary_client.py@e96221fa:L1481-L1497 — a key scoped by the conversation,
     *      only where the endpoint takes it; hermes also hashes the static prefix, here the conversation alone is the scope
     *
     * @return array<string, mixed>
     */
    private static function cacheKey(string $model, int $conversationId): array {
        return ProviderProfile::cacheFor(ModelRef::parse($model)) === PromptCache::Key ? ['prompt_cache_key' => 'conversation-' . $conversationId] : [];
    }

    /**
     * Streams one model call; null when the model failed or ran out of its seconds. Spend is settled and recorded
     * either way. The seconds bound the call itself: a provider that keeps the connection alive with keep-alive
     * comments but never answers would otherwise hold the run until the HTTP transfer timeout.
     */
    private function callModel(RunState $state, ChatRequest $request, int $reserved, float $seconds): ?ChatResponse {
        $started = ($this->clock)();
        $cancellation = new CompositeCancellation($state->cancellation, new TimeoutCancellation($seconds));
        $state->streamBegan = false;
        try {
            $response = null;
            foreach ($this->llm->stream($request, $cancellation) as $event) {
                $state->streamBegan = true;
                if ($event instanceof TextDelta) {
                    $state->observer->textDelta($state->run, $event->text);
                } elseif ($event instanceof StreamEnd) {
                    $response = $event->response;
                }
            }
            if ($response === null) {
                throw new LlmException(ErrorKind::Network, 'Stream ended without a final event');
            }
        } catch (CancelledException $e) {
            if ($state->cancellation->isRequested()) {
                $this->runs->settle($state->run->id, $reserved, 0, $state->run->promptAnchor);
                $this->events->modelCalled($state->run->id, 'agent', $request, null, 'cancelled', ($this->clock)() - $started);
                throw $e;
            }
            $this->events->modelCalled($state->run->id, 'agent', $request, null, ErrorKind::Timeout->value, ($this->clock)() - $started);
            $state->lastError = ErrorKind::Timeout;
            $this->logger->warning('Model call ran out of time', ['run' => $state->run->id, 'seconds' => round($seconds, 1)]);
            $this->settle($state, $reserved, new Usage());
            $this->record($state, $request->model, null, ErrorKind::Timeout->value, $started, new Usage());
            return null;
        } catch (LlmException $e) {
            $this->events->modelCalled($state->run->id, 'agent', $request, null, $e->kind->value, ($this->clock)() - $started);
            $state->lastError = $e->kind;
            $this->logger->error('Model call failed', ['run' => $state->run->id, 'kind' => $e->kind->value, 'status' => $e->status]);
            $this->settle($state, $reserved, new Usage());
            $this->record($state, $request->model, null, $e->kind->value, $started, new Usage());
            return null;
        } catch (Throwable $e) {
            $this->logger->error('Model call failed', ['run' => $state->run->id, 'error' => $e::class]);
            $this->events->modelCalled($state->run->id, 'agent', $request, null, 'unknown', ($this->clock)() - $started);
            $this->settle($state, $reserved, new Usage());
            $this->record($state, $request->model, null, 'unknown', $started, new Usage());
            return null;
        }
        $state->lastError = null;
        $this->events->modelCalled($state->run->id, 'agent', $request, $response, null, ($this->clock)() - $started);
        $this->settle($state, $reserved, $response->usage);
        $this->record($state, $response->model !== '' ? $response->model : $request->model, $response->finishReason->value, null, $started, $response->usage);
        return $response;
    }

    private function settle(RunState $state, int $reserved, Usage $usage): void {
        $this->runs->settle($state->run->id, $reserved, max(0, $usage->totalTokens()), max(0, $usage->promptTokens()));
        $state->run = $this->runs->find($state->run->id) ?? $state->run;
    }

    /** The run waits for the owner: saved as waiting, no final message — the decision resumes it. */
    private function pause(RunState $state): Run {
        $this->flushTime($state);
        $this->runs->setStatus($state->run->id, RunStatus::WaitingApproval);
        $paused = $this->runs->find($state->run->id) ?? $state->run;
        $state->observer->waiting($paused, t('agent.agent_loop.zhdu_podtverzhdeniya_vladeltsa'));
        $this->logger->info('Run waits for approval', ['run' => $state->run->id]);
        return $paused;
    }

    /** The answer says the agent has no such ability or feature: worth a look in self_info first. */
    private static function deniesAbility(string $text): bool {
        $lower = mb_strtolower($text);
        foreach (self::DENIAL_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** Whether the run has called a tool of this name. */
    private function called(int $runId, string $name): bool {
        try {
            foreach ($this->transcript->runEntries($runId) as $entry) {
                foreach ($entry->message->toolCalls as $call) {
                    if ($call->name === $name) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return true;
        }
        return false;
    }

    /** The answer reports a done action in the form of the system note instead of calling a tool. */
    private static function reportsAnAction(string $text): bool {
        foreach (self::ACTION_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function saveAnswer(RunState $state, string $text): void {
        try {
            // the answer stays what the model wrote (jobs and reports read it); the note follows it as a system note, not
            // as words of the agent: a note in the agent's own voice was copied by the model instead of calling a tool
            $this->transcript->appendAssistant($state->run->conversationId, $state->run->id, $text, []);
            $note = $this->actionsNote($state->run->id);
            if ($note !== '') {
                $this->transcript->appendUser($state->run->conversationId, $state->run->id, $note);
            }
        } catch (Throwable $e) {
            $this->logger->error('Could not save the final answer', ['run' => $state->run->id, 'error' => $e::class]);
        }
    }

    /**
     * What the run did, kept with its answer. Later runs see earlier ones without their tool rounds, so without this note
     * the model doubts its own actions and repeats them (a message sent twice, a card shown twice). Reads are left out.
     *
     * spec: BRIEF.md#контекст — history of earlier runs without tool rounds, with the actions they made
     */
    private function actionsNote(int $runId): string {
        $calls = [];
        $lines = [];
        try {
            foreach ($this->transcript->runEntries($runId) as $entry) {
                foreach ($entry->message->toolCalls as $call) {
                    $calls[$call->id] = $call;
                }
                $call = $entry->message->toolCallId === null ? null : ($calls[$entry->message->toolCallId] ?? null);
                $tool = $call === null ? null : $this->registry->get($call->name);
                if ($call === null || $tool === null || count($lines) >= 10) {
                    continue;
                }
                /** @var mixed $args */
                $args = json_decode($call->argumentsJson, true);
                if (!is_array($args)) {
                    continue;
                }
                /** @var array<string, mixed> $args */
                if ($tool->danger($args) === DangerClass::Read) {
                    continue;
                }
                $result = preg_replace('~\s+~u', ' ', $entry->message->content) ?? '';
                $lines[] = sprintf('%s %s → %s', $call->name, mb_substr($call->argumentsJson, 0, 200), mb_substr($result, 0, 160));
            }
        } catch (Throwable $e) {
            $this->logger->warning('Actions of the run were not listed', ['run' => $runId, 'error' => $e::class]);
        }
        return $lines === [] ? '' : sprintf(t('agent.agent_loop.actions_note'), implode('; ', $lines));
    }

    /**
     * After a limit the model gets one call without tools to sum up; other stops get a short explanation.
     *
     * ref: references/hermes-agent/agent/turn_iteration_prep.py@e96221f:L406-L416 — the grace call after the budget ends
     */
    private function finish(RunState $state, StopReason $reason): Run {
        $run = $state->run;
        if ($state->partial !== '' && $reason !== StopReason::Answered) {
            // The fragment was already on the owner's screen: a stop keeps it instead of a bare report.
            $this->saveAnswer($state, $state->partial);
            $text = $state->partial . $this->report($state, $reason);
        } elseif ($reason === StopReason::Answered) {
            $messages = $this->transcript->runMessages($run->id);
            $text = $state->finalText ?? ($messages === [] ? '' : $messages[array_key_last($messages)]->content);
            $text = trim($text) === '' ? t('agent.agent_loop.model_vernula_pustoy_otvet') : $text;
        } elseif ($reason->isLimit()) {
            $summary = $this->callModel(
                $state,
                $this->request($state, [], min(self::SUMMARY_TOKENS, $this->maxTokens), sprintf(t('agent.agent_loop.limit_ischerpan_kratko_podvedi_itog'), $reason->label())),
                0,
                self::SUMMARY_SECONDS,
            );
            $text = $summary === null || trim($summary->text) === '' ? t('agent.agent_loop.itog_podvesti_ne_udalos') : $summary->text;
            if ($summary !== null) {
                $this->saveAnswer($state, $summary->text);
            }
            $text .= $this->report($state, $reason);
        } else {
            $text = t('agent.agent_loop.ne_udalos_zavershit_zadachu') . $this->report($state, $reason);
            // Closes the task in the history: without an answer the next run would take it up again.
            $this->saveAnswer($state, $text);
        }

        $this->flushTime($state);
        $this->runs->finish($run->id, $reason->status(), $reason);
        $final = $this->runs->find($run->id) ?? $run;
        $this->events->runFinished($run->id, $reason->status()->value, $reason->value, $final->activeMs);
        $state->observer->finished($final, $text, $reason);
        $this->logger->info('Run finished', ['run' => $run->id, 'reason' => $reason->value, 'turns' => $final->turns, 'tokens' => $final->tokensUsed]);
        return $final;
    }

    /** /stop: calls without a result are closed as interrupted, the run is stopped. */
    private function stop(RunState $state): Run {
        foreach ($this->transcript->pendingCalls($state->run->id) as $call) {
            $this->transcript->appendToolResult($state->run->conversationId, $state->run->id, $call->callId, ToolCallStatus::Interrupted, t('agent.agent_loop.ostanovleno_vladeltsem'));
        }
        // Closes the task in the history, so the next run does not take a stopped task up again.
        $this->saveAnswer($state, \Mercurius\I18n\t(self::STOPPED_NOTE));
        $this->flushTime($state);
        $this->runs->finish($state->run->id, StopReason::Stopped->status(), StopReason::Stopped);
        $final = $this->runs->find($state->run->id) ?? $state->run;
        $this->events->runFinished($state->run->id, 'stopped', StopReason::Stopped->value, $final->activeMs);
        $state->observer->finished($final, t('agent.agent_loop.ostanovleno'), StopReason::Stopped);
        $this->logger->info('Run stopped', ['run' => $state->run->id]);
        return $final;
    }

    private function report(RunState $state, StopReason $reason): string {
        $run = $this->runs->find($state->run->id) ?? $state->run;
        return sprintf(
            t('agent.agent_loop.ostanovleno_hodov_tokenov_vremya_s'),
            $reason->label(),
            $run->turns,
            $run->tokensUsed,
            (int) round($this->elapsedMs($state) / 1000),
        );
    }

    private function elapsedMs(RunState $state): int {
        return $state->run->activeMs + (int) round((($this->clock)() - $state->flushedAt) * 1000.0);
    }

    private function flushTime(RunState $state): void {
        $now = ($this->clock)();
        $ms = (int) round(($now - $state->flushedAt) * 1000.0);
        if ($ms > 0) {
            $this->runs->addActiveTime($state->run->id, $ms);
        }
        $state->flushedAt = $now;
        $state->run = $this->runs->find($state->run->id) ?? $state->run;
    }

    private function record(RunState $state, string $model, ?string $finishReason, ?string $errorKind, float $started, Usage $usage): void {
        try {
            $this->calls->record(new LlmCallRecord(
                conversationId: $state->run->conversationId,
                runId: $state->run->id,
                purpose: 'agent',
                model: (string) ModelRef::parse($model),
                finishReason: $finishReason,
                errorKind: $errorKind,
                inputTokens: max(0, $usage->inputTokens),
                outputTokens: max(0, $usage->outputTokens),
                cacheReadTokens: max(0, $usage->cacheReadTokens),
                cacheWriteTokens: max(0, $usage->cacheWriteTokens),
                reasoningTokens: max(0, $usage->reasoningTokens),
                costUsd: $usage->costUsd,
                durationMs: max(0, (int) round((($this->clock)() - $started) * 1000.0)),
            ));
        } catch (Throwable $e) {
            $this->logger->error('Could not record model usage', ['error' => $e::class]);
        }
    }

    /** @param list<Message> $messages */
    private static function chars(array $messages): int {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen($message->content);
            foreach ($message->toolCalls as $call) {
                $chars += mb_strlen($call->argumentsJson) + mb_strlen($call->name);
            }
        }
        return $chars;
    }

    private static function isShutdown(Throwable $e): bool {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ShutdownException) {
                return true;
            }
        }
        return false;
    }
}
