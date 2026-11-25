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

    /** The first owner of the agent (the earliest active person whose role has the permission owner); null before one wrote. */
    public function owner(): ?Member;

    /**
     * spec: brief/participants.md#свои-роли — every active person whose role has the permission owner
     *
     * @return list<Member> by id
     */
    public function owners(): array;

    /**
     * spec: brief/participants.md#свои-роли — the agent keeps at least one owner
     *
     * @param list<string> $roles
     */
    public function countActivePeople(array $roles): int;

    /**
     * Another active person becomes an owner: they get the role admin. False for an agent, a member who is not active or
     * one who already is an owner.
     *
     * spec: brief/participants.md#свои-роли — /owner adds another administrator
     */
    public function addOwner(int $to): bool;

    /** @return array<int, int> former owners (not the owner now) => when their ownership ended, unix time */
    public function formerOwners(): array;

    /** The participant's account in a channel (in Telegram, the user id), or null when they have none there. */
    public function externalId(int $principalId, string $channel): ?string;

    /** Gives the participant an identity in a channel; an identity that already exists is kept as it is. */
    public function bindIdentity(int $principalId, string $channel, string $externalId): void;

    /**
     * The member of the account from the configuration: an existing one as it is, else a new administrator (a person).
     *
     * spec: brief/participants.md#свои-роли — on the first start TELEGRAM_ADMIN_ID becomes an administrator
     */
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

    /**
     * Sets the role and status of a member whatever they were; null when it would leave the agent without an owner or
     * make an agent an owner.
     */
    public function setRole(int $id, Role $role, MemberStatus $status): ?Member;
}
