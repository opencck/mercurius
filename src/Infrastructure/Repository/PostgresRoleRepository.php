<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\RoleRepository;

/**
 * spec: brief/participants.md#свои-роли
 */
final class PostgresRoleRepository implements RoleRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function all(): array {
        $roles = [];
        foreach ($this->db->query('SELECT name, title, position, array_to_json(permissions)::text AS permissions, policy::text AS policy FROM participant_roles ORDER BY position, name') as $row) {
            $name = $row['name'] ?? null;
            $title = $row['title'] ?? null;
            $position = $row['position'] ?? null;
            if (!is_string($name) || !is_string($title) || !is_int($position)) {
                continue;
            }
            $roles[] = ['name' => $name, 'title' => $title, 'position' => $position, 'permissions' => self::strings($row['permissions'] ?? null), 'policy' => self::object($row['policy'] ?? null)];
        }
        return $roles;
    }

    public function save(string $name, string $title, int $position, array $permissions, array $policy): void {
        $this->db->execute(
            'INSERT INTO participant_roles (name, title, position, permissions, policy) VALUES (?, ?, ?, ?::text[], ?::jsonb)
             ON CONFLICT (name) DO UPDATE SET title = excluded.title, position = excluded.position, permissions = excluded.permissions, policy = excluded.policy',
            [$name, $title, $position, '{' . implode(',', $permissions) . '}', json_encode((object) $policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
    }

    public function remove(string $name): bool {
        return $this->db->execute(
            'DELETE FROM participant_roles WHERE name = ? AND NOT EXISTS (SELECT 1 FROM principals WHERE role = ?)',
            [$name, $name],
        )->getRowCount() === 1;
    }

    /** @return list<string> */
    private static function strings(mixed $json): array {
        /** @var mixed $list */
        $list = is_string($json) ? json_decode($json, true, 4, JSON_THROW_ON_ERROR) : null;
        if (!is_array($list)) {
            return [];
        }
        /** @var list<string> $strings */
        $strings = array_values(array_filter($list, is_string(...)));
        return $strings;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $json): array {
        /** @var mixed $decoded */
        $decoded = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        return is_array($decoded) ? array_combine(array_map('strval', array_keys($decoded)), array_values($decoded)) : [];
    }
}
