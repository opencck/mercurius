<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use DateTimeImmutable;

/**
 * Compaction state of a conversation: the summary of its early part, where that part ends, failed attempts.
 *
 * spec: brief/agent-loop.md#контекст
 */
final class Compaction {
    public function __construct(
        public readonly ?string $summary,
        /** id of the last message the summary covers; the model gets only messages after it */
        public readonly int $uptoId,
        public readonly int $failures,
        public readonly ?DateTimeImmutable $cooldownUntil,
    ) {}
}
