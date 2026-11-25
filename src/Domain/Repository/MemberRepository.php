<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;

interface MemberRepository {
    public function find(int $id): ?Member;

    /** @return list<Member> every participant, by id */
    public function all(): array;

    public function findByIdentity(string $channel, string $externalId): ?Member;

    /** The participant who owns the agent now; null before the first owner wrote. */
    public function owner(): ?Member;

    /**
     * Ownership passes to an active person: the owner becomes an admin. What the agent has goes with it (its key, its
     * own jobs); what the former owner kept as owner stays theirs (memory about them, their projects).
     *
     * spec: brief/participants.md#несколько-пользователей — the owner passes ownership on, the personal stays with the former owner
     */
    public function transferOwnership(int $from, int $to): bool;

    /** @return array<int, int> former owners (not the owner now) => when their ownership ended, unix time */
    public function formerOwners(): array;

    /** The participant's account in a channel (in Telegram, the user id), or null when they have none there. */
    public function externalId(int $principalId, string $channel): ?string;

    /** Gives the participant an identity in a channel; an identity that already exists is kept as it is. */
    public function bindIdentity(int $principalId, string $channel, string $externalId): void;

    /** Creates the owner (a person) and binds the identity; returns the existing owner when there already is one. */
    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Member;

    /** Creates a pending visitor (a person) and binds the identity; returns the existing participant when the identity is taken. */
    public function createVisitor(string $channel, string $externalId, ?string $displayName): Member;

    /**
     * A connecting agent of the network under its name (channel "a2a"); an existing one keeps its id and gets the role.
     *
     * spec: brief/network.md#как-сделано-связь-двух-агентов — a connected agent is a participant of kind agent
     */
    public function createAgent(string $name, Role $role): Member;

    /** The owner's decision on a pending visitor, taken atomically; null when it was already decided. */
    public function decideVisitor(int $id, Role $role, MemberStatus $status): ?Member;

    /** Sets the role and status of a participant whatever they were; the owner is never changed (null). */
    public function setRole(int $id, Role $role, MemberStatus $status): ?Member;
}
