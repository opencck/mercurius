<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use DateTimeImmutable;

/**
 * How one part of a delegated task went: what was asked, which model did it, how it ended and what it cost.
 *
 * spec: brief/memory.md#память-слои — layer 7, outcomes of tasks with their profiles and models
 */
final class Experience {
    /** @param array<string, int> $profile notable scores by axis */
    public function __construct(
        public readonly int $conversationId,
        public readonly ?int $principalId,
        public readonly ?int $runId,
        public readonly string $goal,
        public readonly string $role,
        public readonly array $profile,
        public readonly string $model,
        /** the first try's model when the part cascaded */
        public readonly ?string $firstModel,
        /** done, failed, limit, stopped */
        public readonly string $status,
        public readonly ?string $verdict,
        public readonly ?string $reason,
        public readonly int $turns,
        public readonly int $tokens,
        public readonly int $durationMs,
        public readonly int $id = 0,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {}
}
