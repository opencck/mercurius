<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use DateTimeImmutable;

/**
 * One step of a run: the run itself, a model call, a tool, an approval, a compaction, a classification.
 *
 * spec: brief/operations.md#команды-и-отчёты-владельца
 * ref: notes/hermes-agent.md#учёт-токенов-и-стоимости — four token buckets and reasoning, cost with how sure it is
 */
final class TraceSpan {
    public const RUN = 'run';
    public const MODEL = 'model';
    public const TOOL = 'tool';
    public const APPROVAL = 'approval';
    public const COMPACTION = 'compaction';
    public const CLASSIFICATION = 'classification';
    /** a worker run of this run; attrs.run is its id */
    public const WORKER = 'worker';

    public const RUNNING = 'running';
    public const OK = 'ok';
    public const ERROR = 'error';
    public const CANCELLED = 'cancelled';

    public const COST_ACTUAL = 'actual';
    public const COST_ESTIMATED = 'estimated';
    public const COST_UNKNOWN = 'unknown';

    /** @param array<string, scalar|null> $attrs */
    public function __construct(
        public readonly int $runId,
        public readonly ?int $parentId,
        public readonly string $kind,
        public readonly string $name,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?int $durationMs,
        public readonly string $status,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $reasoningTokens = 0,
        public readonly ?float $costUsd = null,
        public readonly string $costStatus = self::COST_UNKNOWN,
        public readonly ?string $error = null,
        public readonly array $attrs = [],
        public readonly ?int $id = null,
    ) {}

    /**
     * How sure a cost of a call is: OpenRouter reports it, for other providers it is an estimate by catalog prices.
     *
     * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — only OpenRouter reports a cost
     */
    public static function costStatusOf(string $model, ?float $costUsd): string {
        return match (true) {
            $costUsd === null => self::COST_UNKNOWN,
            ModelRef::parse($model)->provider === ModelRef::OPENROUTER => self::COST_ACTUAL,
            default => self::COST_ESTIMATED,
        };
    }
}
