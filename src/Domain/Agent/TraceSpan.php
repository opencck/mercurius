<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use DateTimeImmutable;

/**
 * One step of a run: the run itself, a model call, a tool, an approval, a compaction, a classification.
 *
 * spec: BRIEF.md#трассировка-и-метрики
 * ref: notes/hermes-agent.md#учёт-токенов-и-стоимости — four token buckets and reasoning, cost with how sure it is
 */
final class TraceSpan {
    public const RUN = 'run';
    public const MODEL = 'model';
    public const TOOL = 'tool';
    public const APPROVAL = 'approval';
    public const COMPACTION = 'compaction';
    public const CLASSIFICATION = 'classification';

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
}
