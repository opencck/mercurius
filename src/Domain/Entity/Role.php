<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

use InvalidArgumentException;

/**
 * A role of participants: one object per name, so roles compare with ===. The owner's role is fixed and has every
 * permission; the others are the owner's list — admin, user and visitor are only the starting set. People and other agents
 * take the same roles; only a person can be the owner.
 *
 * spec: brief/participants.md#свои-роли
 */
final class Role {
    public const OWNER = 'owner';
    /** the starting set: name => [title ('' — the translated name), position, permissions] */
    public const BUILTIN = [
        'admin' => ['', 10, ['tools', 'skills', 'projects', 'secrets', 'admit', 'participants', 'publish', 'reports']],
        'user' => ['', 20, ['tools', 'skills', 'projects', 'secrets']],
        'visitor' => ['', 30, []],
    ];

    /** @var array<string, self> */
    private static array $known = [];

    private string $title;
    private int $position;
    /** @var list<Permission> */
    private array $permissions = [];

    private function __construct(public readonly string $value) {
        $this->title = '';
        $this->position = PHP_INT_MAX;
    }

    /** The role of this name; an unknown name is a role without permissions until the catalog defines it. */
    public static function of(string $name): self {
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Bad role name "%s"', $name));
        }
        if (self::$known === []) {
            self::reset();
        }
        return self::$known[$name] ??= new self($name);
    }

    public static function from(string $name): self {
        return self::of($name);
    }

    public static function tryFrom(string $name): ?self {
        return preg_match('/^[a-z0-9_-]{1,32}$/', $name) === 1 && (self::$known === [] || isset(self::$known[$name]) || $name === self::OWNER) ? self::of($name) : null;
    }

    public static function owner(): self {
        return self::of(self::OWNER);
    }

    public static function admin(): self {
        return self::of('admin');
    }

    public static function user(): self {
        return self::of('user');
    }

    public static function visitor(): self {
        return self::of('visitor');
    }

    /** @param list<Permission> $permissions */
    public static function define(string $name, string $title, int $position, array $permissions): self {
        $role = self::of($name);
        $role->title = $title;
        $role->position = $position;
        $role->permissions = $permissions;
        return $role;
    }

    /** No role is defined: the catalog of an instance defines its list from scratch. */
    public static function clear(): void {
        foreach (self::$known as $role) {
            $role->permissions = [];
            $role->title = '';
            $role->position = PHP_INT_MAX;
        }
        self::$known[self::OWNER] ??= new self(self::OWNER);
    }

    /** Back to the starting set (an instance without a catalog, tests). */
    public static function reset(): void {
        self::clear();
        foreach (self::BUILTIN as $name => [$title, $position, $permissions]) {
            self::define($name, $title, $position, array_map(static fn(string $p): Permission => Permission::from($p), $permissions));
        }
    }

    /** @return list<self> the roles a participant may get, in their order */
    public static function assignable(): array {
        if (self::$known === []) {
            self::reset();
        }
        $roles = array_values(array_filter(self::$known, static fn(self $r): bool => $r->value !== self::OWNER && $r->position !== PHP_INT_MAX));
        usort($roles, static fn(self $a, self $b): int => [$a->position, $a->value] <=> [$b->position, $b->value]);
        return $roles;
    }

    public function isOwner(): bool {
        return $this->value === self::OWNER;
    }

    public function may(Permission $permission): bool {
        return $this->isOwner() || in_array($permission, $this->permissions, true);
    }

    /** @return list<Permission> */
    public function permissions(): array {
        return $this->isOwner() ? Permission::cases() : $this->permissions;
    }

    /** The title the owner gave; '' — none (the built-in roles are named by their translation). */
    public function title(): string {
        return $this->isOwner() ? '' : $this->title;
    }

    public function position(): int {
        return $this->isOwner() ? 0 : $this->position;
    }
}
