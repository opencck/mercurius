<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Stored overrides of participant policies.
 *
 * spec: brief/agent-loop.md#лимиты
 */
interface PolicyRepository {
    /** @return array<string, mixed>|null overrides, or null when the participant has none */
    public function find(int $principalId): ?array;

    /** @param array<string, mixed> $overrides */
    public function save(int $principalId, array $overrides): void;
}
