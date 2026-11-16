<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;

final class InMemoryPrincipalRepository implements PrincipalRepository {
    /** @var array<string, int> channel:external id => principal id */
    public array $identities = [];
    /** @var array<positive-int, Principal> */
    private array $principals = [];
    private ?int $ownerId = null;

    public function find(int $id): ?Principal {
        return $this->principals[$id] ?? null;
    }

    public function findByIdentity(string $channel, string $externalId): ?Principal {
        $id = $this->identities[$channel . ':' . $externalId] ?? null;
        return $id === null ? null : $this->find($id);
    }

    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal {
        $owner = $this->ownerId === null ? $this->add(Role::Owner, $displayName, PrincipalStatus::Active) : $this->principals[$this->ownerId];
        $this->ownerId = $owner->id;
        $this->identities[$channel . ':' . $externalId] ??= $owner->id;
        return $owner;
    }

    public function createVisitor(string $channel, string $externalId, ?string $displayName): Principal {
        $existing = $this->findByIdentity($channel, $externalId);
        if ($existing !== null) {
            return $existing;
        }
        $visitor = $this->add(Role::Visitor, $displayName, PrincipalStatus::Pending);
        $this->identities[$channel . ':' . $externalId] = $visitor->id;
        return $visitor;
    }

    public function decideVisitor(int $id, Role $role, PrincipalStatus $status): ?Principal {
        $current = $this->principals[$id] ?? null;
        if ($current === null || $current->status !== PrincipalStatus::Pending) {
            return null;
        }
        return $this->principals[$current->id] = new Principal($current->id, $current->kind, $role, $current->displayName, $status);
    }

    /** Puts a ready participant in the store, bound to a channel identity. */
    public function put(Principal $principal, string $channel, string $externalId): void {
        $this->principals[$principal->id] = $principal;
        $this->identities[$channel . ':' . $externalId] = $principal->id;
    }

    private function add(Role $role, ?string $displayName, PrincipalStatus $status): Principal {
        $id = $this->principals === [] ? 1 : max(array_keys($this->principals)) + 1;
        return $this->principals[$id] = new Principal($id, PrincipalKind::Human, $role, $displayName, $status);
    }
}
