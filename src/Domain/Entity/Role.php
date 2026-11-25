<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

use InvalidArgumentException;

/**
 * A role of members: one object per name, so roles compare with ===. A role is a set of permissions; a role with the
 * permission owner makes its holders owners of the agent and has every permission. admin, user and reader are only
 * the starting set. People and other agents take the same roles; only a person can be an owner.
 *
 * spec: brief/participants.md#свои-роли
 */
final class Role {
    /** the name no role of the catalog may take: it was the fixed role of the single owner before */
    public const RESERVED = 'owner';
    /** the starting set: name => [title ('' — the translated name), position, permissions] */
    public const BUILTIN = [
        'admin' => ['', 10, ['owner']],
        'user' => ['', 20, ['tools', 'skills', 'projects', 'secrets']],
        'reader' => ['', 30, []],
    ];

    /** @var array<string, self> */
    private static array $known = [];
    /** the starting set or a catalog has defined the roles */
    private static bool $loaded = false;

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
        if (!self::$loaded) {
            self::reset();
        }
        return self::$known[$name] ??= new self($name);
    }

    public static function from(string $name): self {
        return self::of($name);
    }

    public static function tryFrom(string $name): ?self {
        return preg_match('/^[a-z0-9_-]{1,32}$/', $name) === 1 && (!self::$loaded || isset(self::$known[$name])) ? self::of($name) : null;
    }

    public static function admin(): self {
        return self::of('admin');
    }

    public static function user(): self {
        return self::of('user');
    }

    public static function reader(): self {
        return self::of('reader');
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
        self::$loaded = true;
        foreach (self::$known as $role) {
            $role->permissions = [];
            $role->title = '';
            $role->position = PHP_INT_MAX;
        }
    }

    /** Back to the starting set (an instance without a catalog, tests). */
    public static function reset(): void {
        self::clear();
        foreach (self::BUILTIN as $name => [$title, $position, $permissions]) {
            self::define($name, $title, $position, array_map(static fn(string $p): Permission => Permission::from($p), $permissions));
        }
    }

    /** @return list<self> the roles a member may get, in their order */
    public static function assignable(): array {
        if (!self::$loaded) {
            self::reset();
        }
        $roles = array_values(array_filter(self::$known, static fn(self $r): bool => $r->position !== PHP_INT_MAX));
        usort($roles, static fn(self $a, self $b): int => [$a->position, $a->value] <=> [$b->position, $b->value]);
        return $roles;
    }

    /**
     * spec: brief/participants.md#свои-роли — whoever holds one of these roles is an owner of the agent
     *
     * @return list<string> the names of the roles with the permission owner, in their order
     */
    public static function ownerRoles(): array {
        return array_values(array_map(static fn(self $r): string => $r->value, array_filter(self::assignable(), static fn(self $r): bool => $r->isOwner())));
    }

    public function isOwner(): bool {
        return in_array(Permission::Owner, $this->permissions, true);
    }

    public function may(Permission $permission): bool {
        return $this->isOwner() || in_array($permission, $this->permissions, true);
    }

    /** @return list<Permission> */
    public function permissions(): array {
        return $this->isOwner() ? Permission::cases() : $this->permissions;
    }

    /** @return list<Permission> the permissions as the catalog keeps them, without the ones owner implies */
    public function granted(): array {
        return $this->permissions;
    }

    /** The title the owner gave; '' — none (the built-in roles are named by their translation). */
    public function title(): string {
        return $this->title;
    }

    public function position(): int {
        return $this->position;
    }
}
