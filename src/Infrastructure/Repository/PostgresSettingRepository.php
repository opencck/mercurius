<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SettingRepository;

final class PostgresSettingRepository implements SettingRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT key, value, secret FROM settings ORDER BY key') as $row) {
            if (isset($row['key']) && is_string($row['key'])) {
                $found[$row['key']] = [
                    'value' => isset($row['value']) && is_string($row['value']) ? $row['value'] : null,
                    'secret' => ($row['secret'] ?? false) === true,
                ];
            }
        }
        return $found;
    }

    public function set(string $key, ?string $value, bool $secret, ?int $by): void {
        $this->db->execute(
            'INSERT INTO settings (key, value, secret, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value, secret = excluded.secret, updated_by = excluded.updated_by, updated_at = now()',
            [$key, $value, $secret, $by],
        );
    }

    public function delete(string $key): bool {
        return $this->db->execute('DELETE FROM settings WHERE key = ? RETURNING key', [$key])->fetchRow() !== null;
    }
}
