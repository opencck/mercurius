<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Closure;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\RoleRepository;

final class InMemoryRoleRepository implements RoleRepository {
    /** @var array<string, array{name: string, title: string, position: int, permissions: list<string>, policy: array<string, mixed>}> */
    public array $roles = [];

    /** @param (Closure(string): bool)|null $held whether a participant has the role */
    public function __construct(private readonly ?Closure $held = null) {
        foreach (Role::BUILTIN as $name => [$title, $position, $permissions]) {
            $this->roles[$name] = ['name' => $name, 'title' => $title, 'position' => $position, 'permissions' => $permissions, 'policy' => []];
        }
    }

    public function all(): array {
        $roles = array_values($this->roles);
        usort($roles, static fn(array $a, array $b): int => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);
        return $roles;
    }

    public function save(string $name, string $title, int $position, array $permissions, array $policy): void {
        $this->roles[$name] = ['name' => $name, 'title' => $title, 'position' => $position, 'permissions' => $permissions, 'policy' => $policy];
    }

    public function remove(string $name): bool {
        if (!isset($this->roles[$name]) || ($this->held !== null && ($this->held)($name))) {
            return false;
        }
        unset($this->roles[$name]);
        return true;
    }
}
