<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;

interface PrincipalRepository {
    public function find(int $id): ?Principal;

    /** @return list<Principal> every participant, by id */
    public function all(): array;

    public function findByIdentity(string $channel, string $externalId): ?Principal;

    /** The participant's account in a channel (in Telegram, the user id), or null when they have none there. */
    public function externalId(int $principalId, string $channel): ?string;

    /** Gives the participant an identity in a channel; an identity that already exists is kept as it is. */
    public function bindIdentity(int $principalId, string $channel, string $externalId): void;

    /** Creates the owner (a person) and binds the identity; returns the existing owner when there already is one. */
    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal;

    /** Creates a pending visitor (a person) and binds the identity; returns the existing participant when the identity is taken. */
    public function createVisitor(string $channel, string $externalId, ?string $displayName): Principal;

    /**
     * A connecting agent of the network under its name (channel "a2a"); an existing one keeps its id and gets the role.
     *
     * spec: BRIEF.md#сеть-агентов — a connected agent is a participant of kind agent
     */
    public function createAgent(string $name, Role $role): Principal;

    /** The owner's decision on a pending visitor, taken atomically; null when it was already decided. */
    public function decideVisitor(int $id, Role $role, PrincipalStatus $status): ?Principal;

    /** Sets the role and status of a participant whatever they were; the owner is never changed (null). */
    public function setRole(int $id, Role $role, PrincipalStatus $status): ?Principal;
}
