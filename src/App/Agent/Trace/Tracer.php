<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Trace;

use DateTimeImmutable;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\Message;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\TraceRepository;
use Psr\Log\LoggerInterface;
use Throwable;

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
    /** @var array<int, int> run id => id of its run step */
    private array $runSpans = [];

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly ModelCatalogRepository $catalog,
        private readonly LoggerInterface $logger,
    ) {}

    public function runStarted(int $runId, string $model): void {
        $this->safely(function () use ($runId, $model): void {
            $this->runSpans[$runId] = $this->traces->openRun($runId, $model);
        });
    }

    public function modelCalled(int $runId, string $purpose, ChatRequest $request, ?ChatResponse $response, ?string $error, float $seconds): void {
        $this->safely(function () use ($runId, $purpose, $request, $response, $error, $seconds): void {
            $model = (string) ModelRef::parse($request->model);
            $usage = $response === null ? new Usage() : $response->usage;
            [$cost, $costStatus] = $this->cost($model, $usage);
            $id = $this->traces->record(new TraceSpan(
                runId: $runId,
                parentId: $this->runSpan($runId, $model),
                kind: $purpose === 'compaction' ? TraceSpan::COMPACTION : TraceSpan::MODEL,
                name: $model,
                startedAt: self::startedAt($seconds),
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
            $this->traces->payload($id, self::request($request), $response === null ? ['error' => $error] : self::response($response));
        });
    }

    public function toolFinished(int $runId, string $name, ?array $args, ToolResult $result, float $seconds): void {
        $this->safely(function () use ($runId, $name, $args, $result, $seconds): void {
            $this->traces->record(new TraceSpan(
                runId: $runId,
                parentId: $this->runSpan($runId, ''),
                kind: TraceSpan::TOOL,
                name: $name,
                startedAt: self::startedAt($seconds),
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
        $this->safely(function () use ($runId, $tool, $outcome, $seconds): void {
            $this->traces->record(new TraceSpan(
                runId: $runId,
                parentId: $this->runSpan($runId, ''),
                kind: TraceSpan::APPROVAL,
                name: $tool,
                startedAt: self::startedAt($seconds),
                durationMs: self::ms($seconds),
                status: $outcome === 'approved' ? TraceSpan::OK : TraceSpan::CANCELLED,
                attrs: ['outcome' => $outcome],
            ));
        });
    }

    public function runFinished(int $runId, string $status, ?string $reason, int $activeMs): void {
        $this->safely(function () use ($runId, $status, $reason, $activeMs): void {
            $this->traces->closeRun($runId, $status === 'done' ? TraceSpan::OK : ($status === 'stopped' ? TraceSpan::CANCELLED : TraceSpan::ERROR), $activeMs, $reason);
            unset($this->runSpans[$runId]);
        });
    }

    private function runSpan(int $runId, string $model): int {
        return $this->runSpans[$runId] ??= $this->traces->openRun($runId, $model);
    }

    /** @return array{?float, string} */
    private function cost(string $model, Usage $usage): array {
        if ($usage->costUsd !== null) {
            return [$usage->costUsd, TraceSpan::COST_ACTUAL];
        }
        $estimate = $this->catalog->find(ModelRef::parse($model))?->estimateCost($usage);
        return $estimate === null ? [null, TraceSpan::COST_UNKNOWN] : [$estimate, TraceSpan::COST_ESTIMATED];
    }

    /** @return array<string, mixed> */
    private static function request(ChatRequest $request): array {
        return [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'tools' => array_map(static fn($tool): string => $tool->name, $request->tools),
            'messages' => array_map(static fn(Message $m): array => [
                'role' => $m->role->value,
                'content' => SecretMasker::mask($m->content),
                'tool_call_id' => $m->toolCallId,
                'tool_calls' => self::calls($m->toolCalls),
            ], $request->messages),
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

    /** @param \Closure(): void $write */
    private function safely(\Closure $write): void {
        try {
            $write();
        } catch (Throwable $e) {
            $this->logger->error('Could not write a trace', ['error' => $e::class]);
        }
    }
}
