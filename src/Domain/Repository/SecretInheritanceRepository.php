<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Which wider layers of secrets a context inherits: the owner's (a project) and the agent's. Both are off by default.
 *
 * spec: brief/skills-mcp.md#подстановка-секретов
 */
interface SecretInheritanceRepository {
    /** @return array{owner: bool, agent: bool, by: ?int} by — who opened their own secrets to the context */
    public function of(string $context): array;

    /**
     * @param 'owner'|'agent' $layer
     * @param int|null $by for the owner's layer: whose secrets are opened; the consent holds only while they own it
     */
    public function set(string $context, string $layer, bool $on, ?int $by = null): void;
}
