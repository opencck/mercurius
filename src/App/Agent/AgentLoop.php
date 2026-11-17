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
use OpenCCK\App\Agent\Tool\BatchPlanner;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\LongRunning;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\ToolCallValidator;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Tool\PreparesCall;
use OpenCCK\App\Agent\Tool\RequestsAccess;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRef;
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

use function Amp\async;
use function Amp\Future\await;

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
    public const TEXT_ACTION_NOTE = 'agent.agent_loop.text_action';
    /** how the system note of done actions begins (now and before), in both languages */
    private const ACTION_MARKERS = ['[Сделано в этом запуске', '[Done in this run', '[Служебная заметка системы', '[System note'];
    public const CONTINUE_NOTE = 'agent.agent_loop.continue_note';
    public const EMPTY_NOTE = 'agent.agent_loop.empty_note';
    private const SUMMARY_SECONDS = 60.0;
    private const SUMMARY_TOKENS = 1000;
    /** Saved as the answer of a stopped run: the model sees the task as closed, not as waiting. */
    public const STOPPED_NOTE = 'agent.agent_loop.stopped_note';
    private const WARN_SHARE = 0.8;

    private readonly ToolCallValidator $validator;
    private readonly BatchPlanner $planner;
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
    ) {
        $this->validator = new ToolCallValidator($registry);
        $this->planner = new BatchPlanner();
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** Runs until the run ends; on shutdown returns the run still running, to resume after restart. */
    public function run(Run $run, ParticipantPolicy $policy, Principal $principal, RunObserver $observer, Cancellation $cancellation): Run {
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

    /** @return StopReason|null null when the run paused for an approval */
    private function iterate(RunState $state): ?StopReason {
        while (true) {
            $this->deliverInbox($state);
            $reason = $this->checkLimits($state);
            if ($reason !== null) {
                return $reason;
            }
            // Before compaction: its summary call costs money too.
            $over = $this->spend?->exceeded();
            if ($over !== null) {
                $this->logger->warning('Spend cap reached; the run stops', ['run' => $state->run->id, 'reason' => $over]);
                return StopReason::SpendCap;
            }
            if ($this->compactor?->ensure($state->run, $state->systemPrompt, $this->historyLimit, $this->estimate($state), cancellation: $state->cancellation) === true) {
                $this->loadContext($state);
            }
            $reserved = $this->reserve($state);
            if ($reserved === null) {
                return StopReason::TokenBudget;
            }

            $left = ($state->run->limits->runTimeout * 1000 - $this->elapsedMs($state)) / 1000;
            $response = $this->callModel($state, $this->request($state, $this->registry->definitions($state->policy), $this->maxTokens, null, $this->tail($state)), $reserved, max(1.0, $left));
            if ($response === null) {
                if ($this->elapsedMs($state) >= $state->run->limits->runTimeout * 1000) {
                    // The deadline cut the call off: a limit, with a summary, not a failure of the model.
                    return StopReason::Deadline;
                }
                // ref: references/hermes-agent/agent/turn_truncation.py@e96221fa:L313-L345 — a stream that broke after
                //      it began is a stub, not a verdict: nothing was saved yet, so the call is made once more
                // Before the first event the client already retried: only a break after it is repeated here.
                if ($state->streamBegan && in_array($state->lastError, [ErrorKind::Server, ErrorKind::Network, ErrorKind::Timeout], true) && !$state->streamRetried) {
                    $state->streamRetried = true;
                    continue;
                }
                // spec: BRIEF.md#контекст — the provider proved the context too long: compact once and ask again
                if ($state->lastError === ErrorKind::ContextLength && !$state->overflowRetried && $this->compactor !== null) {
                    $state->overflowRetried = true;
                    $this->compactor->ensure($state->run, $state->systemPrompt, $this->historyLimit, $this->estimate($state), force: true, cancellation: $state->cancellation);
                    $this->loadContext($state);
                    continue;
                }
                // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — one switch per run to another model
                if ($this->fallback !== null && !$state->switched) {
                    $next = $this->fallback->next($state->model ?? $state->run->model, $state->run->conversationId);
                    if ($next !== null) {
                        $state->switched = true;
                        $state->model = $next;
                        continue;
                    }
                }
                return StopReason::ModelFailed;
            }
            $state->overflowRetried = false;
            $state->streamRetried = false;
            if (!$response->hasToolCalls()) {
                // ref: references/hermes-agent/agent/turn_truncation.py@e96221fa:L313-L379 (_continue_text) — an answer
                //      cut off by the output limit is continued and stitched, up to 4 times
                // A cut answer with no text is an empty one: the output went to reasoning, a continuation would repeat it.
                if ($response->finishReason === FinishReason::Length && trim($response->text) !== '' && $state->continuations < self::MAX_CONTINUATIONS) {
                    $state->continuations++;
                    $state->partial .= $response->text;
                    continue;
                }
                $text = $state->partial . $response->text;
                // ref: references/hermes-agent/agent/turn_empty_response.py@e96221fa:L105-L155 (_retry_empty) — an empty
                //      answer is asked again with a hint, up to 3 times
                if (trim($text) === '' && $state->emptyRetries < self::MAX_EMPTY_RETRIES) {
                    $state->emptyRetries++;
                    continue;
                }
                // spec: BRIEF.md#агентский-цикл-и-харнесс — an action is done only by a tool call: an answer that reports one in
                //      words (it copies the system note of earlier runs) is shown back once with a reminder
                // ref: references/hermes-agent/agent/prompt_builder.py@e96221fa:L420-L440 — hermes warns models that "claim
                //      completion without tool calls" in the prompt; here the loop also catches the copied note
                if (!$state->textActionRetried && self::reportsAnAction($text)) {
                    $state->textActionRetried = true;
                    $state->textAction = $text;
                    $state->partial = '';
                    $this->logger->notice('An action was written as text, not called', ['run' => $state->run->id]);
                    continue;
                }
                $state->textAction = null;
                $state->partial = '';
                $state->finalText = $text;
                $this->saveAnswer($state, $text);
                return StopReason::Answered;
            }
            // A step with tools ends whatever answer was being continued or retried: the next answer starts clean.
            if ($state->partial !== '' || $state->emptyRetries > 0) {
                $this->logger->info('Model turned to tools mid-answer; the fragment is dropped', ['run' => $state->run->id]);
            }
            $state->partial = '';
            $state->continuations = 0;
            $state->emptyRetries = 0;

            $outcome = $this->validator->validate($response->toolCalls, $state->policy, $this->transcript->callIds($state->run->id));
            if ($outcome->truncated) {
                // hermes refuses cut-off arguments at once (turn_tool_validation.py L216-L237); we repeat the request once.
                if ($state->truncatedRetried) {
                    return StopReason::Truncated;
                }
                $state->truncatedRetried = true;
                continue;
            }
            $state->truncatedRetried = false;

            $calls = $this->prepare($state, $outcome->calls);
            if (!$this->persist($state, $response->text, $calls)) {
                return StopReason::PersistFailed;
            }
            if (trim($response->text) !== '') {
                $state->observer->commentary($state->run, $response->text);
            }
            $reason = $this->execute($state, $calls);
            if ($reason !== null || $state->paused) {
                return $reason;
            }

            $state->invalidTurns = $outcome->hasValid() ? 0 : $state->invalidTurns + 1;
            if ($state->invalidTurns >= 3) {
                return StopReason::InvalidCalls;
            }
            $this->flushTime($state);
        }
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
        return $this->execute($state, $calls);
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
        $messages = [Message::system($state->systemPrompt), ...$summary, ...$state->history, ...$this->contextMessages($state), ...$tail];
        $state->messagesAtCall = count($this->transcript->runMessages($state->run->id));
        if ($closingPrompt !== null) {
            $messages[] = Message::user($closingPrompt);
        }
        return new ChatRequest($state->model ?? $state->run->model, $messages, $tools, $maxTokens, reasoningEffort: $this->reasoningEffort);
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

    /**
     * Tools that complete their arguments do it before the call is saved, so the card, the grant and the run all see
     * the same completed call; a call that cannot be completed gets its reason as an error.
     *
     * spec: BRIEF.md#скрипты-браузера-решение-владельца-2026-11-17
     *
     * @param list<ValidatedCall> $calls
     * @return list<ValidatedCall>
     */
    private function prepare(RunState $state, array $calls): array {
        return array_map(function (ValidatedCall $call) use ($state): ValidatedCall {
            if (!$call->executable() || !$call->tool instanceof PreparesCall || $call->args === null) {
                return $call;
            }
            try {
                $prepared = $call->tool->prepare($call->args, $state->principal, $state->policy, $state->cancellation);
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (Throwable $e) {
                $prepared = $e::class;
            }
            return is_string($prepared)
                ? new ValidatedCall($call->call, $call->tool, $call->args, $prepared, $call->danger)
                : new ValidatedCall($call->call, $call->tool, $prepared, null, $call->tool->danger($prepared));
        }, $calls);
    }

    /** @param list<ValidatedCall> $calls */
    private function persist(RunState $state, string $text, array $calls): bool {
        $stored = [];
        foreach ($calls as $position => $call) {
            $stored[] = new StoredToolCall(
                $call->call->id,
                $position,
                $call->call->name,
                $call->args,
                $call->call->argumentsJson,
                $call->danger,
                ToolCallStatus::Pending,
            );
        }
        try {
            $this->transcript->appendAssistant($state->run->conversationId, $state->run->id, $text, $stored);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Could not save the model answer; no tool runs', ['run' => $state->run->id, 'error' => $e::class]);
            return false;
        }
    }

    /**
     * Segments in order: parallel reads, writes one by one. Results are written in the original order as each
     * segment ends; the loop guard and the 80% warning ride on the last result of the answer.
     *
     * @param list<ValidatedCall> $calls
     */
    private function execute(RunState $state, array $calls): ?StopReason {
        $lastId = $calls === [] ? null : $calls[array_key_last($calls)]->call->id;
        $halted = false;
        $warn = false;
        foreach ($this->planner->segments($calls) as $segment) {
            if ($halted) {
                foreach ($segment as $call) {
                    $this->writeResult($state, $call, ToolResult::error(t('agent.agent_loop.ne_vypolneno_zapusk_ostanovlen_potomu')));
                }
                continue;
            }

            // An irreversible call is alone in its segment (BatchPlanner): ask, use a grant, or refuse first.
            $admission = $this->admit($state, $segment[0]);
            if ($admission === false) {
                $state->paused = true;
                return null;
            }
            $futures = [];
            foreach ($segment as $call) {
                $futures[] = $admission instanceof ToolResult
                    ? \Amp\Future::complete($admission)
                    : async(fn(): ToolResult => $this->runCall($state, $call, $admission === 'granted' || $admission === 'approved'));
            }
            $results = await($futures);
            if ($admission === 'granted') {
                $first = $results[0] ?? null;
                if ($first instanceof ToolResult) {
                    $results[0] = new ToolResult($first->content . t('agent.agent_loop.vypolneno_po_sohranennomu_razresheniyu_v'), $first->isError);
                }
            }
            foreach ($segment as $index => $call) {
                $result = $results[$index] ?? null;
                if (!$result instanceof ToolResult) {
                    throw new \LogicException('A tool call produced no result');
                }
                $verdict = $call->args === null ? GuardVerdict::Ok : $state->guard->observe(LoopGuard::signature($call->call->name, $call->args, $result->content));
                $halted = $halted || $verdict === GuardVerdict::Halt;
                $warn = $warn || $verdict === GuardVerdict::Warn;
                if ($call->call->id === $lastId) {
                    $result = $this->withNotes($state, $result, $warn || $halted);
                }
                $this->writeResult($state, $call, $result);
            }
        }
        return $halted ? StopReason::LoopGuard : null;
    }

    /**
     * Whether a call may run now. Only irreversible calls are checked.
     *
     * spec: BRIEF.md#подтверждения-и-запреты — approval, saved grants, nobody to ask means refusal
     * ref: references/hermes-agent/tools/approval.py@e96221f:L1421-L1479 (check_all_command_guards) — guards in order:
     *      saved permissions, then asking a person; with nobody to ask (cron, API) the answer is an immediate denial
     *
     * @return bool|'granted'|'approved'|ToolResult true — run; 'granted' — run by a saved grant; 'approved' — run, the owner
     *         pressed "allow"; false — paused for the owner;
     *         ToolResult — do not run, this is the result
     */
    private function admit(RunState $state, ValidatedCall $call): bool|string|ToolResult {
        // spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс — the verifier reads; no grant lets it write
        if ($state->policy->readOnly && $call->executable() && $call->danger !== DangerClass::Read) {
            return ToolResult::error(t('agent.agent_loop.zdes_dostupno_tolko_chtenie_etot'));
        }
        // spec: BRIEF.md#часть-1--синхронное-делегирование — a worker gets no irreversible action, a saved grant included
        if (!$state->policy->irreversible && $call->executable() && $call->danger === DangerClass::Irreversible) {
            return ToolResult::error(t('agent.agent_loop.vorkeru_neobratimye_deystviya_nedostupny'));
        }
        if (!$call->executable() || $call->danger !== DangerClass::Irreversible || $call->tool === null || $call->args === null) {
            return true;
        }
        $approval = $this->approvals?->forCall($state->run->id, $call->call->id);
        if ($approval !== null) {
            return match ($approval->status) {
                ApprovalStatus::Approved => 'approved',
                ApprovalStatus::Pending => false,
                ApprovalStatus::Denied => ToolResult::error(t('agent.agent_loop.otkazano_vladeltsem') . ($approval->reason !== null ? ': ' . $approval->reason : '.') . t('agent.agent_loop.deystvie_ne_vypolneno')),
                ApprovalStatus::Expired => ToolResult::error(t('agent.agent_loop.vladelets_ne_otvetil_vovremya_molchanie')),
            };
        }
        $key = $call->tool instanceof GrantKeyed ? $call->tool->grantKey($call->args) : null;
        // spec: BRIEF.md#подтверждения-и-запреты — after outside data a grant is not used: the text read may be someone's
        //      instructions, so the owner sees every irreversible action of this run on a card
        $outside = $this->runs->find($state->run->id)?->outside;
        if ($key !== null && $outside === null && $this->approvals?->hasGrant($state->principal->id, $key) === true) {
            $this->logger->info('Irreversible call allowed by a saved grant', ['run' => $state->run->id, 'tool' => $call->call->name, 'key' => $key]);
            return 'granted';
        }
        // spec: BRIEF.md#участник-просит-инструмент-решение-владельца-2026-11-17 — asking for a tool reaches the owner always
        if ($this->gate === null || $this->approvals === null || (!$state->policy->mayRequestApproval && !$call->tool instanceof RequestsAccess)) {
            return ToolResult::error(t('agent.agent_loop.deystvie_trebuet_podtverzhdeniya_vladelt'));
        }
        // Saved as waiting before the owner sees the card: a press that comes at once must find the run waiting.
        $this->transcript->setCallStatus($state->run->id, $call->call->id, ToolCallStatus::AwaitingApproval);
        $this->runs->setStatus($state->run->id, RunStatus::WaitingApproval);
        $this->gate->request($this->runs->find($state->run->id) ?? $state->run, $call, $state->principal);
        return false;
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

    private function runCall(RunState $state, ValidatedCall $call, bool $approved = false): ToolResult {
        if (!$call->executable() || $call->tool === null) {
            return ToolResult::error($call->error ?? t('agent.agent_loop.vyzov_ne_proshel_proverku'));
        }
        $this->transcript->startCall($state->run->id, $call->call->id);
        if ($call->tool instanceof ReadsOutside && $call->args !== null && $call->tool->readsOutside($call->args)) {
            // marked before the call: even a failed read may have brought text in
            $this->runs->markOutside($state->run->id, $call->call->name);
        }
        // The status message is in the chat: secrets in the arguments are masked there too.
        $preview = $call->args === null ? $call->call->argumentsJson : (json_encode(SecretMasker::maskArgs($call->args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $state->observer->toolStarted($state->run, $call->call->name, mb_substr(SecretMasker::mask($preview), 0, 200));
        $remaining = max(1, (int) floor(($state->run->limits->runTimeout * 1000 - $this->elapsedMs($state)) / 1000));
        $timeout = $call->tool instanceof LongRunning
            ? max(1, min($call->tool->timeout(), $remaining))
            : max(1, min($call->tool->timeout(), $state->run->limits->toolTimeout, $remaining));
        $context = new ToolContext($state->run->id, $state->run->conversationId, $state->principal, $state->cancellation, $state->policy, $call->call->id, $approved);
        $started = ($this->clock)();
        $result = $this->executor->run($call, $context, $timeout);
        $this->events->toolFinished($state->run->id, $call->call->name, $call->args, $result, ($this->clock)() - $started);
        if ($call->tool instanceof RequestsAccess && !$result->isError) {
            // the owner gave the tool: it is offered from the next turn of this very run
            $state->policy = $state->policy->withTool($call->tool->grantedTool($call->args ?? []));
        }
        return $result;
    }

    private function writeResult(RunState $state, ValidatedCall $call, ToolResult $result): void {
        $status = $result->isError ? ToolCallStatus::Failed : ToolCallStatus::Done;
        $this->transcript->appendToolResult($state->run->conversationId, $state->run->id, $call->call->id, $status, $result->content);
    }

    /**
     * ref: references/hermes-agent/agent/turn_iteration_prep.py@e96221f:L41-L99, L183-L186 — one-time budget warning
     *      appended to the last tool result, not a separate message (keeps role alternation and the prompt cache)
     */
    private function withNotes(RunState $state, ToolResult $result, bool $repeating): ToolResult {
        $notes = [];
        if ($repeating) {
            $notes[] = \Mercurius\I18n\t(LoopGuard::WARNING);
        }
        $limit = $this->nearLimit($state);
        if ($limit !== null && !$state->run->warned) {
            $notes[] = sprintf(t('agent.agent_loop.izrashodovano_bolshe_80_imita_podvodi'), $limit);
            $this->runs->markWarned($state->run->id);
            $state->run = $this->runs->find($state->run->id) ?? $state->run;
        }
        return $notes === [] ? $result : new ToolResult($result->content . "\n\n[" . implode(' ', $notes) . ']', $result->isError);
    }

    private function nearLimit(RunState $state): ?string {
        $run = $state->run;
        return match (true) {
            $run->turns >= self::WARN_SHARE * (float) $run->limits->maxTurns => t('agent.agent_loop.hodov'),
            $run->tokensUsed >= self::WARN_SHARE * (float) $run->limits->tokenBudget => t('agent.agent_loop.tokenov'),
            $this->elapsedMs($state) >= self::WARN_SHARE * (float) ($run->limits->runTimeout * 1000) => t('agent.agent_loop.vremeni'),
            default => null,
        };
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
