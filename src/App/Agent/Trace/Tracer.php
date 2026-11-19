<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Trace;

use Amp\Future;
use DateTimeImmutable;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\Message;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\TraceRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

/**
 * Writes the trace of a run from its events: the run step, a step per model call, tool and approval, with tokens,
 * time and cost; full requests and answers of model calls go to payloads, masked. A failed write is logged and the
 * run goes on.
 *
 * spec: BRIEF.md#трассировка-и-метрики
 * ref: docs/backendbytes-multi-agent-orchestrator-backend.md:L528-L554 (fetched 2026-11-16) — a span per sub-agent
 *      call with tokens spent and the error; here a span per step, stored in PostgreSQL instead of OpenTelemetry
 * ref: references/hermes-agent/agent/usage_pricing.py@e96221fa:L739 (estimate_usage_cost) — cost with a status:
 *      the provider's figure, an estimate from catalog prices, or unknown
 */
final class Tracer implements AgentEvents {
    /** Writes waiting for the database; beyond this, new ones are dropped rather than held in memory. */
    private const QUEUE_LIMIT = 500;

    /** @var array<int, int> run id => id of its run step */
    private array $runSpans = [];
    /** @var array<int, int> run id => messages of the last request already saved in a payload */
    private array $sent = [];
    /** @var \SplQueue<\Closure(): void> */
    private \SplQueue $queue;
    /** @var Future<void>|null */
    private ?Future $worker = null;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly ModelCatalogRepository $catalog,
        private readonly LoggerInterface $logger,
    ) {
        $this->queue = new \SplQueue();
    }

    /** Waits until queued writes are done; for tests and an orderly stop. */
    public function idle(): void {
        while ($this->worker !== null) {
            $this->worker->await();
        }
    }

    public function runStarted(int $runId, string $model): void {
        $this->safely(function () use ($runId, $model): void {
            $this->runSpans[$runId] = $this->traces->openRun($runId, $model);
        });
    }

    public function modelCalled(int $runId, string $purpose, ChatRequest $request, ?ChatResponse $response, ?string $error, float $seconds): void {
        $startedAt = self::startedAt($seconds);
        // Each request repeats the whole conversation: a payload keeps only what is new since the run's last call.
        $from = 0;
        if ($purpose === 'agent') {
            $from = $this->sent[$runId] ?? 0;
            $from = $from > count($request->messages) ? 0 : $from;
            $this->sent[$runId] = count($request->messages);
        }
        $this->safely(function () use ($runId, $purpose, $request, $response, $error, $seconds, $startedAt, $from): void {
            $model = (string) ModelRef::parse($request->model);
            $usage = $response === null ? new Usage() : $response->usage;
            [$cost, $costStatus] = $this->cost($model, $usage);
            $id = $this->traces->record(new TraceSpan(
                runId: $runId,
                parentId: $this->runSpan($runId, $model),
                kind: $purpose === 'compaction' ? TraceSpan::COMPACTION : TraceSpan::MODEL,
                name: $model,
                startedAt: $startedAt,
                durationMs: self::ms($seconds),
                status: $error === null ? TraceSpan::OK : ($error === 'cancelled' ? TraceSpan::CANCELLED : TraceSpan::ERROR),
                inputTokens: max(0, $usage->inputTokens),
                outputTokens: max(0, $usage->outputTokens),
                cacheReadTokens: max(0, $usage->cacheReadTokens),
                cacheWriteTokens: max(0, $usage->cacheWriteTokens),
                reasoningTokens: max(0, $usage->reasoningTokens),
                costUsd: $cost,
                costStatus: $costStatus,
                error: $error,
                attrs: ['purpose' => $purpose, 'finish' => $response?->finishReason->value, 'tool_calls' => $response === null ? 0 : count($response->toolCalls)],
            ));
            $this->traces->payload($id, self::request($request, $from), $response === null ? ['error' => $error] : self::response($response));
        });
    }

    public function toolFinished(int $runId, string $name, ?array $args, ToolResult $result, float $seconds): void {
        $startedAt = self::startedAt($seconds);
        $this->safely(function () use ($runId, $name, $args, $result, $seconds, $startedAt): void {
            $this->traces->record(new TraceSpan(
                runId: $runId,
                parentId: $this->runSpan($runId, ''),
                kind: TraceSpan::TOOL,
                name: $name,
                startedAt: $startedAt,
                durationMs: self::ms($seconds),
                status: $result->isError ? TraceSpan::ERROR : TraceSpan::OK,
                error: $result->isError ? SecretMasker::mask(mb_substr($result->content, 0, 300)) : null,
                attrs: [
                    'args' => $args === null ? null : mb_substr(self::json(SecretMasker::maskArgs($args)), 0, 500),
                    'result_chars' => mb_strlen($result->content),
                ],
            ));
        });
    }

    public function approvalDecided(int $runId, string $tool, string $outcome, float $seconds): void {
        $startedAt = self::startedAt($seconds);
        $this->safely(function () use ($runId, $tool, $outcome, $seconds, $startedAt): void {
            $this->traces->record(new TraceSpan(
                runId: $runId,
                parentId: $this->runSpan($runId, ''),
                kind: TraceSpan::APPROVAL,
                name: $tool,
                startedAt: $startedAt,
                durationMs: self::ms($seconds),
                status: $outcome === 'approved' ? TraceSpan::OK : TraceSpan::CANCELLED,
                attrs: ['outcome' => $outcome],
            ));
        });
    }

    public function workerFinished(int $parentRunId, int $workerRunId, string $model, string $status, float $seconds): void {
        $startedAt = self::startedAt($seconds);
        $this->safely(function () use ($parentRunId, $workerRunId, $model, $status, $seconds, $startedAt): void {
            $this->traces->record(new TraceSpan(
                runId: $parentRunId,
                parentId: $this->runSpan($parentRunId, ''),
                kind: TraceSpan::WORKER,
                name: '#' . $workerRunId,
                startedAt: $startedAt,
                durationMs: self::ms($seconds),
                status: $status === 'done' ? TraceSpan::OK : ($status === 'stopped' ? TraceSpan::CANCELLED : TraceSpan::ERROR),
                attrs: ['run' => $workerRunId, 'model' => $model, 'status' => $status],
            ));
        });
    }

    public function runFinished(int $runId, string $status, ?string $reason, int $activeMs): void {
        $this->safely(function () use ($runId, $status, $reason, $activeMs): void {
            $this->traces->closeRun($runId, $status === 'done' ? TraceSpan::OK : ($status === 'stopped' ? TraceSpan::CANCELLED : TraceSpan::ERROR), $activeMs, $reason);
            unset($this->runSpans[$runId]);
        });
        unset($this->sent[$runId]);
    }

    private function runSpan(int $runId, string $model): int {
        return $this->runSpans[$runId] ??= $this->traces->openRun($runId, $model);
    }

    /** @return array{?float, string} */
    private function cost(string $model, Usage $usage): array {
        $ref = ModelRef::parse($model);
        if ($usage->costUsd !== null) {
            // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — only OpenRouter reports a cost; for the others the
            // model router puts in an estimate from catalog prices
            return [$usage->costUsd, TraceSpan::costStatusOf($model, $usage->costUsd)];
        }
        $estimate = $this->catalog->find($ref)?->estimateCost($usage);
        return $estimate === null ? [null, TraceSpan::COST_UNKNOWN] : [$estimate, TraceSpan::COST_ESTIMATED];
    }

    /** @return array<string, mixed> */
    private static function request(ChatRequest $request, int $from): array {
        return [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'from' => $from,
            'total' => count($request->messages),
            'tools' => array_map(static fn($tool): string => $tool->name, $request->tools),
            'messages' => array_map(static fn(Message $m): array => [
                'role' => $m->role->value,
                'content' => SecretMasker::mask($m->content),
                'tool_call_id' => $m->toolCallId,
                'tool_calls' => self::calls($m->toolCalls),
            ], array_slice($request->messages, $from)),
        ];
    }

    /** @return array<string, mixed> */
    private static function response(ChatResponse $response): array {
        return [
            'model' => $response->model,
            'id' => $response->id,
            'finish' => $response->finishReason->value,
            'text' => SecretMasker::mask($response->text),
            'tool_calls' => self::calls($response->toolCalls),
        ];
    }

    /**
     * @param list<ToolCall> $calls
     * @return list<array{id: string, name: string, arguments: string}>
     */
    private static function calls(array $calls): array {
        return array_map(static function (ToolCall $call): array {
            /** @var mixed $args */
            $args = json_decode($call->argumentsJson, true);
            return [
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => is_array($args) ? self::json(SecretMasker::maskArgs($args)) : SecretMasker::mask($call->argumentsJson),
            ];
        }, $calls);
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private static function startedAt(float $seconds): DateTimeImmutable {
        return (new DateTimeImmutable())->modify(sprintf('-%d milliseconds', self::ms($seconds)));
    }

    /** @return non-negative-int */
    private static function ms(float $seconds): int {
        return max(0, (int) round($seconds * 1000.0));
    }

    /**
     * Queues a write: the run does not wait for the database, and writes go one after another, in order. A write
     * that fails is logged and the run goes on.
     *
     * @param \Closure(): void $write
     */
    private function safely(\Closure $write): void {
        if ($this->queue->count() >= self::QUEUE_LIMIT) {
            $this->logger->warning('Trace queue is full; a step was not written');
            return;
        }
        $this->queue->enqueue($write);
        $this->worker ??= async(function (): void {
            try {
                while (!$this->queue->isEmpty()) {
                    $next = $this->queue->dequeue();
                    try {
                        $next();
                    } catch (Throwable $e) {
                        $this->logger->error('Could not write a trace', ['error' => $e::class]);
                    }
                }
            } finally {
                $this->worker = null;
            }
        });
    }
}
