<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;

final class InMemoryPrincipalRepository implements PrincipalRepository {
    /** @var array<string, Principal> */
    public array $identities = [];
    private ?Principal $owner = null;

    public function findByIdentity(string $channel, string $externalId): ?Principal {
        return $this->identities[$channel . ':' . $externalId] ?? null;
    }

    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal {
        $this->owner ??= new Principal(1, PrincipalKind::Human, Role::Owner, $displayName);
        $this->identities[$channel . ':' . $externalId] = $this->owner;
        return $this->owner;
    }
}
