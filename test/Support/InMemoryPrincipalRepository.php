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

    public function owner(): ?Principal {
        foreach ($this->principals as $principal) {
            if ($principal->role === Role::owner()) {
                return $principal;
            }
        }
        return null;
    }

    /** @var array<int, int> */
    private array $former = [];

    public function transferOwnership(int $from, int $to): bool {
        if ($from < 1 || $to < 1) {
            return false;
        }
        $old = $this->principals[$from] ?? null;
        $new = $this->principals[$to] ?? null;
        if ($old === null || $new === null || $from === $to || !$old->isOwner() || $new->kind !== PrincipalKind::Human || $new->status !== PrincipalStatus::Active) {
            return false;
        }
        $this->principals[$from] = new Principal($old->id, $old->kind, Role::admin(), $old->displayName, $old->status);
        $this->principals[$to] = new Principal($new->id, $new->kind, Role::owner(), $new->displayName, $new->status);
        $this->ownerId = $to;
        $this->former[$from] = time();
        unset($this->former[$to]);
        return true;
    }

    public function formerOwners(): array {
        return $this->former;
    }

    public function findByIdentity(string $channel, string $externalId): ?Principal {
        $id = $this->identities[$channel . ':' . $externalId] ?? null;
        return $id === null ? null : $this->find($id);
    }

    public function bindIdentity(int $principalId, string $channel, string $externalId): void {
        $this->identities[$channel . ':' . $externalId] ??= $principalId;
    }

    public function externalId(int $principalId, string $channel): ?string {
        foreach ($this->identities as $key => $id) {
            if ($id === $principalId && str_starts_with($key, $channel . ':')) {
                return substr($key, strlen($channel) + 1);
            }
        }
        return null;
    }

    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal {
        $owner = $this->ownerId === null ? $this->add(Role::owner(), $displayName, PrincipalStatus::Active) : $this->principals[$this->ownerId];
        $this->ownerId = $owner->id;
        $this->identities[$channel . ':' . $externalId] ??= $owner->id;
        return $owner;
    }

    public function createVisitor(string $channel, string $externalId, ?string $displayName): Principal {
        $existing = $this->findByIdentity($channel, $externalId);
        if ($existing !== null) {
            return $existing;
        }
        $visitor = $this->add(Role::visitor(), $displayName, PrincipalStatus::Pending);
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

    public function setRole(int $id, Role $role, PrincipalStatus $status): ?Principal {
        $current = $this->principals[$id] ?? null;
        if ($current === null || $current->isOwner()) {
            return null;
        }
        return $this->principals[$current->id] = new Principal($current->id, $current->kind, $role, $current->displayName, $status);
    }

    /** Puts a ready participant in the store, bound to a channel identity. */
    public function put(Principal $principal, string $channel, string $externalId): void {
        $this->principals[$principal->id] = $principal;
        $this->identities[$channel . ':' . $externalId] = $principal->id;
    }

    public function all(): array {
        ksort($this->principals);
        return array_values($this->principals);
    }

    public function createAgent(string $name, Role $role): Principal {
        $existing = $this->findByIdentity('a2a', $name);
        $id = $existing !== null ? $existing->id : ($this->principals === [] ? 1 : max(array_keys($this->principals)) + 1);
        $this->identities['a2a:' . $name] = $id;
        return $this->principals[$id] = new Principal($id, PrincipalKind::Agent, $role, $name, PrincipalStatus::Active);
    }

    private function add(Role $role, ?string $displayName, PrincipalStatus $status): Principal {
        $id = $this->principals === [] ? 1 : max(array_keys($this->principals)) + 1;
        return $this->principals[$id] = new Principal($id, PrincipalKind::Human, $role, $displayName, $status);
    }
}
