<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ApiKeyRepository;

final class PostgresApiKeyRepository implements ApiKeyRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function add(int $principalId, string $name, string $hash): int {
        $row = $this->db->execute('INSERT INTO api_keys (principal_id, name, hash) VALUES (?, ?, ?) RETURNING id', [$principalId, $name, $hash])->fetchRow();
        return self::int($row ?? [], 'id') ?? 0;
    }

    public function principalOf(string $hash): ?int {
        $row = $this->db->execute('UPDATE api_keys SET used_at = now() WHERE hash = ? AND revoked_at IS NULL RETURNING principal_id', [$hash])->fetchRow();
        return self::int($row ?? [], 'principal_id');
    }

    public function active(): array {
        $keys = [];
        foreach ($this->db->query('SELECT id, principal_id, name, extract(epoch FROM created_at)::bigint AS created, extract(epoch FROM used_at)::bigint AS used FROM api_keys WHERE revoked_at IS NULL ORDER BY id') as $row) {
            $name = $row['name'] ?? '';
            $keys[] = [
                'id' => self::int($row, 'id') ?? 0,
                'principal' => self::int($row, 'principal_id') ?? 0,
                'name' => is_string($name) ? $name : '',
                'created' => self::int($row, 'created') ?? 0,
                'used' => self::int($row, 'used'),
            ];
        }
        return $keys;
    }

    public function revoke(int $id): bool {
        return $this->db->execute('UPDATE api_keys SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL RETURNING id', [$id])->fetchRow() !== null;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): ?int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
    }
}
