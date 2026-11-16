<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\Cancellation;
use Amp\CancelledException;
use Closure;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\Tool\BatchPlanner;
use OpenCCK\App\Agent\Tool\ToolCallValidator;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\LlmCallRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Amp\Future\await;

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
    private const SUMMARY_TOKENS = 1000;
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
            $this->transcript->history($run->conversationId, $run->id, $this->historyLimit),
            ($this->clock)(),
        );

        try {
            $reason = $this->resumePending($state) ?? $this->iterate($state);
        } catch (CancelledException $e) {
            if (self::isShutdown($e)) {
                $this->flushTime($state);
                $this->logger->info('Run paused by shutdown', ['run' => $run->id]);
                return $this->runs->find($run->id) ?? $run;
            }
            return $this->stop($state);
        }
        return $this->finish($state, $reason);
    }

    private function iterate(RunState $state): StopReason {
        while (true) {
            $this->deliverInbox($state);
            $reason = $this->checkLimits($state);
            if ($reason !== null) {
                return $reason;
            }
            $reserved = $this->reserve($state);
            if ($reserved === null) {
                return StopReason::TokenBudget;
            }

            $response = $this->callModel($state, $this->request($state, $this->registry->definitions($state->policy), $this->maxTokens), $reserved);
            if ($response === null) {
                return StopReason::ModelFailed;
            }
            if (!$response->hasToolCalls()) {
                $this->saveAnswer($state, $response->text);
                return StopReason::Answered;
            }

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

            if (!$this->persist($state, $response->text, $outcome->calls)) {
                return StopReason::PersistFailed;
            }
            $reason = $this->execute($state, $outcome->calls);
            if ($reason !== null) {
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
            $tool = $state->policy->allowsTool($stored->name) ? $this->registry->get($stored->name) : null;
            $error = $tool === null || $stored->args === null ? 'Инструмент недоступен после перезапуска.' : null;
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
        $messages = $this->transcript->runMessages($state->run->id);
        if ($state->run->promptAnchor > 0) {
            $input = $state->run->promptAnchor + intdiv(self::chars(array_slice($messages, $state->messagesAtCall)), 4);
        } else {
            $input = intdiv(mb_strlen($state->systemPrompt) + self::chars($state->history) + self::chars($messages), 4);
        }
        $reserved = $input + $this->maxTokens;
        return $this->runs->reserve($state->run->id, $reserved) ? $reserved : null;
    }

    /**
     * @param list<\Mercurius\Llm\ToolDefinition> $tools
     * @param positive-int $maxTokens
     */
    private function request(RunState $state, array $tools, int $maxTokens, ?string $closingPrompt = null): ChatRequest {
        $messages = [Message::system($state->systemPrompt), ...$state->history, ...$this->transcript->runMessages($state->run->id)];
        $state->messagesAtCall = count($messages) - 1 - count($state->history);
        if ($closingPrompt !== null) {
            $messages[] = Message::user($closingPrompt);
        }
        return new ChatRequest($state->run->model, $messages, $tools, $maxTokens, reasoningEffort: $this->reasoningEffort);
    }

    /** Streams one model call; null when the model failed. Spend is settled and recorded either way. */
    private function callModel(RunState $state, ChatRequest $request, int $reserved): ?ChatResponse {
        $started = ($this->clock)();
        try {
            $response = null;
            foreach ($this->llm->stream($request, $state->cancellation) as $event) {
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
            $this->runs->settle($state->run->id, $reserved, 0, $state->run->promptAnchor);
            throw $e;
        } catch (LlmException $e) {
            $this->logger->error('Model call failed', ['run' => $state->run->id, 'kind' => $e->kind->value, 'status' => $e->status]);
            $this->settle($state, $reserved, new Usage());
            $this->record($state, $request->model, null, $e->kind->value, $started, new Usage());
            return null;
        } catch (Throwable $e) {
            $this->logger->error('Model call failed', ['run' => $state->run->id, 'error' => $e::class]);
            $this->settle($state, $reserved, new Usage());
            $this->record($state, $request->model, null, 'unknown', $started, new Usage());
            return null;
        }
        $this->settle($state, $reserved, $response->usage);
        $this->record($state, $response->model !== '' ? $response->model : $request->model, $response->finishReason->value, null, $started, $response->usage);
        return $response;
    }

    private function settle(RunState $state, int $reserved, Usage $usage): void {
        $this->runs->settle($state->run->id, $reserved, max(0, $usage->totalTokens()), max(0, $usage->promptTokens()));
        $state->run = $this->runs->find($state->run->id) ?? $state->run;
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
                    $this->writeResult($state, $call, ToolResult::error('Не выполнено: запуск остановлен, потому что действия повторялись.'));
                }
                continue;
            }

            $futures = [];
            foreach ($segment as $call) {
                $futures[] = async(fn(): ToolResult => $this->runCall($state, $call));
            }
            $results = await($futures);
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

    private function runCall(RunState $state, ValidatedCall $call): ToolResult {
        if (!$call->executable() || $call->tool === null) {
            return ToolResult::error($call->error ?? 'Вызов не прошёл проверку.');
        }
        $this->transcript->startCall($state->run->id, $call->call->id);
        $state->observer->toolStarted($state->run, $call->call->name, mb_substr($call->call->argumentsJson, 0, 200));
        $remaining = max(1, (int) floor(($state->run->limits->runTimeout * 1000 - $this->elapsedMs($state)) / 1000));
        $timeout = max(1, min($call->tool->timeout(), $state->run->limits->toolTimeout, $remaining));
        $context = new ToolContext($state->run->id, $state->run->conversationId, $state->principal, $state->cancellation);
        return $this->executor->run($call, $context, $timeout);
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
            $notes[] = LoopGuard::WARNING;
        }
        $limit = $this->nearLimit($state);
        if ($limit !== null && !$state->run->warned) {
            $notes[] = sprintf('Израсходовано больше 80%% лимита (%s). Подводи итог.', $limit);
            $this->runs->markWarned($state->run->id);
            $state->run = $this->runs->find($state->run->id) ?? $state->run;
        }
        return $notes === [] ? $result : new ToolResult($result->content . "\n\n[" . implode(' ', $notes) . ']', $result->isError);
    }

    private function nearLimit(RunState $state): ?string {
        $run = $state->run;
        return match (true) {
            $run->turns >= self::WARN_SHARE * (float) $run->limits->maxTurns => 'ходов',
            $run->tokensUsed >= self::WARN_SHARE * (float) $run->limits->tokenBudget => 'токенов',
            $this->elapsedMs($state) >= self::WARN_SHARE * (float) ($run->limits->runTimeout * 1000) => 'времени',
            default => null,
        };
    }

    private function saveAnswer(RunState $state, string $text): void {
        try {
            $this->transcript->appendAssistant($state->run->conversationId, $state->run->id, $text, []);
        } catch (Throwable $e) {
            $this->logger->error('Could not save the final answer', ['run' => $state->run->id, 'error' => $e::class]);
        }
    }

    /**
     * After a limit the model gets one call without tools to sum up; other stops get a short explanation.
     *
     * ref: references/hermes-agent/agent/turn_iteration_prep.py@e96221f:L406-L416 — the grace call after the budget ends
     */
    private function finish(RunState $state, StopReason $reason): Run {
        $run = $state->run;
        if ($reason === StopReason::Answered) {
            $messages = $this->transcript->runMessages($run->id);
            $text = $messages === [] ? '' : $messages[array_key_last($messages)]->content;
            $text = trim($text) === '' ? 'Модель вернула пустой ответ.' : $text;
        } elseif ($reason->isLimit()) {
            $summary = $this->callModel(
                $state,
                $this->request($state, [], min(self::SUMMARY_TOKENS, $this->maxTokens), sprintf('Лимит исчерпан (%s). Кратко подведи итог: что сделано, что осталось.', $reason->label())),
                0,
            );
            $text = $summary === null || trim($summary->text) === '' ? 'Итог подвести не удалось.' : $summary->text;
            if ($summary !== null) {
                $this->saveAnswer($state, $summary->text);
            }
            $text .= $this->report($state, $reason);
        } else {
            $text = 'Не удалось завершить задачу.' . $this->report($state, $reason);
        }

        $this->flushTime($state);
        $this->runs->finish($run->id, $reason->status(), $reason);
        $final = $this->runs->find($run->id) ?? $run;
        $state->observer->finished($final, $text, $reason);
        $this->logger->info('Run finished', ['run' => $run->id, 'reason' => $reason->value, 'turns' => $final->turns, 'tokens' => $final->tokensUsed]);
        return $final;
    }

    /** /stop: calls without a result are closed as interrupted, the run is stopped. */
    private function stop(RunState $state): Run {
        foreach ($this->transcript->pendingCalls($state->run->id) as $call) {
            $this->transcript->appendToolResult($state->run->conversationId, $state->run->id, $call->callId, ToolCallStatus::Interrupted, 'Остановлено владельцем.');
        }
        $this->flushTime($state);
        $this->runs->finish($state->run->id, StopReason::Stopped->status(), StopReason::Stopped);
        $final = $this->runs->find($state->run->id) ?? $state->run;
        $state->observer->finished($final, 'Остановлено.', StopReason::Stopped);
        $this->logger->info('Run stopped', ['run' => $state->run->id]);
        return $final;
    }

    private function report(RunState $state, StopReason $reason): string {
        $run = $this->runs->find($state->run->id) ?? $state->run;
        return sprintf(
            "\n\nОстановлено: %s. Ходов: %d, токенов: %d, время: %d с.",
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
                purpose: 'agent',
                model: $model,
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
