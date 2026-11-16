<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * Accounting record of one model call: tokens, cost, duration, outcome.
 */
final class LlmCallRecord {
    /**
     * @param non-empty-string $purpose why the call was made: chat, compaction, ...
     * @param non-negative-int $inputTokens
     * @param non-negative-int $outputTokens
     * @param non-negative-int $cacheReadTokens
     * @param non-negative-int $cacheWriteTokens
     * @param non-negative-int $reasoningTokens
     * @param non-negative-int $durationMs
     */
    public function __construct(
        public readonly ?int $conversationId,
        public readonly string $purpose,
        public readonly string $model,
        public readonly ?string $finishReason,
        public readonly ?string $errorKind,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens,
        public readonly int $cacheWriteTokens,
        public readonly int $reasoningTokens,
        public readonly ?float $costUsd,
        public readonly int $durationMs,
        public readonly ?int $runId = null,
    ) {}
}
