<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * Whoever writes to the agent: a person or another agent. Both get the same treatment — a role decides
 * what they may do, and the agent keeps memory about them.
 *
 * spec: brief/participants.md#участники-люди-и-агенты
 */
final class Member {
    /** @param positive-int $id */
    public function __construct(
        public readonly int $id,
        public readonly MemberKind $kind,
        public readonly Role $role,
        public readonly ?string $displayName,
        public readonly MemberStatus $status = MemberStatus::Active,
    ) {}

    /** spec: brief/participants.md#свои-роли — an owner is a person whose role has the permission owner */
    public function isOwner(): bool {
        return $this->kind === MemberKind::Human && $this->role->isOwner();
    }

    public function isAgent(): bool {
        return $this->kind === MemberKind::Agent;
    }
}
