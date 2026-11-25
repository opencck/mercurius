<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\RoleRepository;

use function Mercurius\I18n\t;

/**
 * The owner's list of roles: read at start and after every change, it gives the roles their titles, order and permissions,
 * and the policy service the template of each role.
 *
 * spec: brief/participants.md#свои-роли
 */
final class RoleCatalog {
    /** @var array<string, array<string, mixed>> role => policy template */
    private array $templates = [];

    /** @var (\Closure(): void)|null */
    private ?\Closure $onChange = null;

    /** @var (\Closure(list<string>): int)|null active people holding one of these roles */
    private ?\Closure $owners = null;

    public function __construct(private readonly RoleRepository $roles) {}

    /**
     * spec: brief/participants.md#права-проекта — a change of a role's permissions or template changes the rights of its holders
     *
     * @param \Closure(): void $listener
     */
    public function onChange(\Closure $listener): void {
        $this->onChange = $listener;
    }

    /**
     * spec: brief/participants.md#свои-роли — the agent keeps at least one owner: a change that would leave nobody with
     * the permission owner is refused
     *
     * @param \Closure(list<string>): int $count active people holding one of the roles named
     */
    public function useOwners(\Closure $count): void {
        $this->owners = $count;
    }

    /** @param list<string> $ownerRoles */
    private function keepAnOwner(array $ownerRoles): void {
        if ($this->owners !== null && ($ownerRoles === [] || ($this->owners)(array_values(array_unique($ownerRoles))) === 0)) {
            throw new InvalidArgumentException(t('roles.last_owner'));
        }
    }

    private function changed(): void {
        if ($this->onChange !== null) {
            ($this->onChange)();
        }
    }

    public function load(): void {
        Role::clear();
        $this->templates = [];
        foreach ($this->roles->all() as $row) {
            $permissions = array_values(array_filter(array_map(static fn(string $p): ?Permission => Permission::tryFrom($p), $row['permissions'])));
            Role::define($row['name'], $row['title'], $row['position'], $permissions);
            $this->templates[$row['name']] = $row['policy'];
        }
    }

    /** @return array<string, mixed> */
    public function template(Role $role): array {
        return $this->templates[$role->value] ?? [];
    }

    /** The list for the owner: name, title, place, permissions, template. */
    public function describe(): string {
        $lines = [];
        foreach (Role::assignable() as $role) {
            $template = $this->template($role);
            $lines[] = sprintf(
                '%d. %s%s — %s%s',
                $role->position(),
                $role->value,
                $role->title() === '' ? '' : ' «' . $role->title() . '»',
                $role->permissions() === [] ? t('roles.no_powers') : implode(', ', array_map(static fn(Permission $p): string => $p->value, $role->permissions())),
                $template === [] ? '' : '; ' . json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        }
        return t('roles.list') . "\n" . implode("\n", $lines) . "\n\n" . t('roles.help');
    }

    /** Adds a role at the end of the list, without permissions. */
    public function add(string $name, string $title): string {
        self::checkName($name);
        if (Role::tryFrom($name) !== null && in_array(Role::of($name), Role::assignable(), true)) {
            throw new InvalidArgumentException(sprintf(t('roles.exists'), $name));
        }
        $last = array_reduce(Role::assignable(), static fn(int $max, Role $r): int => max($max, $r->position()), 0);
        $this->roles->save($name, mb_substr(trim($title), 0, 64), $last + 10, [], []);
        $this->load();
        return sprintf(t('roles.added'), $name);
    }

    /**
     * Changes one field of a role: title, position, permissions (a list by commas) or a field of the policy template
     * (tools, max_turns, token_budget, run_timeout, tool_timeout, may_request_approval, daily_spend_usd).
     */
    public function set(string $name, string $field, string $value): string {
        $role = $this->existing($name);
        $title = $role->title();
        $position = $role->position();
        $permissions = array_map(static fn(Permission $p): string => $p->value, $role->granted());
        $policy = $this->template($role);
        switch ($field) {
            case 'title':
                $title = mb_substr(trim($value), 0, 64);
                break;
            case 'position':
                if (!ctype_digit($value)) {
                    throw new InvalidArgumentException(t('roles.bad_position'));
                }
                $position = (int) $value;
                break;
            // spec: brief/participants.md#свои-роли — the old key of a role's permissions is still accepted
            case 'permissions':
            case 'powers':
                $permissions = [];
                foreach (array_filter(array_map('trim', explode(',', $value))) as $permission) {
                    $permissions[] = (Permission::tryFrom($permission) ?? throw new InvalidArgumentException(sprintf(t('roles.bad_power'), $permission, implode(', ', array_map(static fn(Permission $p): string => $p->value, Permission::cases())))))->value;
                }
                break;
            default:
                /** @psalm-suppress MixedAssignment a value of the template is any of its JSON types, checked just below */
                $policy[$field] = self::policyValue($field, $value);
                // a bad field or value is refused here, before it is kept
                ParticipantPolicy::fromArray($policy, new ParticipantPolicy([], new RunLimits(1, 1, 1, 1), null, false));
        }
        $others = array_values(array_filter(Role::ownerRoles(), static fn(string $r): bool => $r !== $name));
        $this->keepAnOwner(in_array(Permission::Owner->value, $permissions, true) ? [...$others, $name] : $others);
        $this->roles->save($name, $title, $position, array_values(array_unique($permissions)), $policy);
        $this->load();
        $this->changed();
        return sprintf(t('roles.changed'), $name);
    }

    public function remove(string $name): string {
        $this->existing($name);
        $this->keepAnOwner(array_values(array_filter(Role::ownerRoles(), static fn(string $r): bool => $r !== $name)));
        if (!$this->roles->remove($name)) {
            throw new InvalidArgumentException(sprintf(t('roles.held'), $name));
        }
        $this->load();
        return sprintf(t('roles.removed'), $name);
    }

    /**
     * The role of the one the agent talks to and its permissions, for self_info: an owner sees owner and every other
     * permission listed.
     *
     * spec: brief/participants.md#свои-роли
     */
    public static function ofMember(Role $role): string {
        $permissions = $role->permissions();
        return sprintf(t('self.limits.role'), self::label($role), $permissions === [] ? t('roles.no_powers') : implode(', ', array_map(static fn(Permission $p): string => $p->value, $permissions)));
    }

    /** How a role is called to people: the title the owner gave, the translation of a built-in role, its name. */
    public static function label(Role $role): string {
        return $role->title() !== '' ? $role->title() : match ($role->value) {
            'admin' => t('service.visitor.admin'),
            'user' => t('service.visitor.polzovatel'),
            'reader' => t('service.visitor.reader'),
            default => $role->value,
        };
    }

    /**
     * The data of a button that gives a role: the built-in roles keep their old one-letter codes, so cards sent before
     * keep working.
     */
    public static function code(Role $role): string {
        return match ($role->value) {
            'admin' => ':a',
            'user' => ':u',
            'reader' => ':t',
            default => '=' . $role->value,
        };
    }

    private function existing(string $name): Role {
        self::checkName($name);
        $role = Role::of($name);
        if (!in_array($role, Role::assignable(), true)) {
            throw new InvalidArgumentException(sprintf(t('roles.unknown'), $name));
        }
        return $role;
    }

    private static function checkName(string $name): void {
        if ($name === Role::RESERVED || preg_match('/^[a-z0-9_-]{1,32}$/', $name) !== 1) {
            throw new InvalidArgumentException(t('roles.bad_name'));
        }
    }

    private static function policyValue(string $field, string $value): mixed {
        $value = trim($value);
        return match (true) {
            $field === 'tools' => array_values(array_filter(array_map('trim', explode(',', $value)))),
            $field === 'may_request_approval' => in_array(strtolower($value), ['1', 'true', 'yes', 'да'], true),
            $field === 'daily_spend_usd' && is_numeric($value) => (float) $value,
            ctype_digit($value) => (int) $value,
            default => $value,
        };
    }
}
