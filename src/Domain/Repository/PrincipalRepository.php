<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;

interface PrincipalRepository {
    public function find(int $id): ?Principal;

    public function findByIdentity(string $channel, string $externalId): ?Principal;

    /** Creates the owner (a person) and binds the identity; returns the existing owner when there already is one. */
    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal;

    /** Creates a pending visitor (a person) and binds the identity; returns the existing participant when the identity is taken. */
    public function createVisitor(string $channel, string $externalId, ?string $displayName): Principal;

    /** The owner's decision on a pending visitor, taken atomically; null when it was already decided. */
    public function decideVisitor(int $id, Role $role, PrincipalStatus $status): ?Principal;
}
