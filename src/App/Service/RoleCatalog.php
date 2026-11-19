<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\RoleRepository;

use function Mercurius\I18n\t;

/**
 * The owner's list of roles: read at start and after every change, it gives the roles their titles, order and powers,
 * and the policy service the template of each role.
 *
 * spec: BRIEF.md#свои-роли-решение-владельца-2026-11-19
 */
final class RoleCatalog {
    /** @var array<string, array<string, mixed>> role => policy template */
    private array $templates = [];

    public function __construct(private readonly RoleRepository $roles) {}

    public function load(): void {
        Role::clear();
        $this->templates = [];
        foreach ($this->roles->all() as $row) {
            $powers = array_values(array_filter(array_map(static fn(string $p): ?Power => Power::tryFrom($p), $row['powers'])));
            Role::define($row['name'], $row['title'], $row['position'], $powers);
            $this->templates[$row['name']] = $row['policy'];
        }
    }

    /** @return array<string, mixed> */
    public function template(Role $role): array {
        return $this->templates[$role->value] ?? [];
    }

    /** The list for the owner: name, title, place, powers, template. */
    public function describe(): string {
        $lines = [];
        foreach (Role::assignable() as $role) {
            $template = $this->template($role);
            $lines[] = sprintf(
                '%d. %s%s — %s%s',
                $role->position(),
                $role->value,
                $role->title() === '' ? '' : ' «' . $role->title() . '»',
                $role->powers() === [] ? t('roles.no_powers') : implode(', ', array_map(static fn(Power $p): string => $p->value, $role->powers())),
                $template === [] ? '' : '; ' . json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        }
        return t('roles.list') . "\n" . implode("\n", $lines) . "\n\n" . t('roles.help');
    }

    /** Adds a role at the end of the list, without powers. */
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
     * Changes one field of a role: title, position, powers (a list by commas) or a field of the policy template
     * (tools, max_turns, token_budget, run_timeout, tool_timeout, may_request_approval, daily_spend_usd).
     */
    public function set(string $name, string $field, string $value): string {
        $role = $this->existing($name);
        $title = $role->title();
        $position = $role->position();
        $powers = array_map(static fn(Power $p): string => $p->value, $role->powers());
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
            case 'powers':
                $powers = [];
                foreach (array_filter(array_map('trim', explode(',', $value))) as $power) {
                    $powers[] = (Power::tryFrom($power) ?? throw new InvalidArgumentException(sprintf(t('roles.bad_power'), $power, implode(', ', array_map(static fn(Power $p): string => $p->value, Power::cases())))))->value;
                }
                break;
            default:
                /** @psalm-suppress MixedAssignment a value of the template is any of its JSON types, checked just below */
                $policy[$field] = self::policyValue($field, $value);
                // a bad field or value is refused here, before it is kept
                ParticipantPolicy::fromArray($policy, new ParticipantPolicy([], new RunLimits(1, 1, 1, 1), null, false));
        }
        $this->roles->save($name, $title, $position, array_values(array_unique($powers)), $policy);
        $this->load();
        return sprintf(t('roles.changed'), $name);
    }

    public function remove(string $name): string {
        $this->existing($name);
        if (!$this->roles->remove($name)) {
            throw new InvalidArgumentException(sprintf(t('roles.held'), $name));
        }
        $this->load();
        return sprintf(t('roles.removed'), $name);
    }

    /** How a role is called to people: the title the owner gave, the translation of a built-in role, its name. */
    public static function label(Role $role): string {
        return $role->title() !== '' ? $role->title() : match ($role->value) {
            'admin' => t('service.visitor.admin'),
            'user' => t('service.visitor.polzovatel'),
            'visitor' => t('service.visitor.razgovor'),
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
            'visitor' => ':t',
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
        if ($name === Role::OWNER || preg_match('/^[a-z0-9_-]{1,32}$/', $name) !== 1) {
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
