<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The owner's list of roles of participants.
 *
 * spec: brief/participants.md#свои-роли-решение-владельца-2026-11-19
 */
interface RoleRepository {
    /** @return list<array{name: string, title: string, position: int, powers: list<string>, policy: array<string, mixed>}> in their order */
    public function all(): array;

    /**
     * Adds the role or replaces it.
     *
     * @param list<string> $powers
     * @param array<string, mixed> $policy
     */
    public function save(string $name, string $title, int $position, array $powers, array $policy): void;

    /** Removes a role no participant has; false when someone has it or there is no such role. */
    public function remove(string $name): bool;
}
