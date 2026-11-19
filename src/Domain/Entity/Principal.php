<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * Whoever writes to the agent: a person or another agent. Both get the same treatment — a role decides
 * what they may do, and the agent keeps memory about them.
 *
 * spec: BRIEF.md#участники-люди-и-агенты
 */
final class Principal {
    /** @param positive-int $id */
    public function __construct(
        public readonly int $id,
        public readonly PrincipalKind $kind,
        public readonly Role $role,
        public readonly ?string $displayName,
        public readonly PrincipalStatus $status = PrincipalStatus::Active,
    ) {}

    public function isOwner(): bool {
        return $this->role->isOwner();
    }

    public function isAgent(): bool {
        return $this->kind === PrincipalKind::Agent;
    }
}
