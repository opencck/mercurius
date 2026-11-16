<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Entity\Principal;

interface PrincipalRepository {
    public function find(int $id): ?Principal;

    public function findByIdentity(string $channel, string $externalId): ?Principal;

    /** Creates the owner (a person) and binds the identity; returns the existing owner when there already is one. */
    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal;
}
