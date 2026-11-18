<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\HttpCookieRepository;

final class PostgresHttpCookieRepository implements HttpCookieRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function cookies(string $scope, string $host): array {
        $live = [];
        foreach ($this->db->execute('SELECT name, value FROM http_cookies WHERE scope = ? AND host = ? AND expires_at > now() ORDER BY name', [$scope, $host]) as $row) {
            $name = $row['name'] ?? null;
            $value = $row['value'] ?? null;
            if (is_string($name) && is_string($value)) {
                $live[$name] = $value;
            }
        }
        return $live;
    }

    public function keep(string $scope, string $host, string $name, string $value, int $expires): void {
        if ($value === '' || $expires <= time()) {
            $this->db->execute('DELETE FROM http_cookies WHERE scope = ? AND host = ? AND name = ?', [$scope, $host, $name]);
            return;
        }
        $this->db->execute(
            'INSERT INTO http_cookies (scope, host, name, value, expires_at) VALUES (?, ?, ?, ?, to_timestamp(?))
             ON CONFLICT (scope, host, name) DO UPDATE SET value = EXCLUDED.value, expires_at = EXCLUDED.expires_at, updated_at = now()',
            [$scope, $host, $name, $value, $expires],
        );
        // expired ones go away as new ones come
        $this->db->execute('DELETE FROM http_cookies WHERE expires_at <= now()');
    }
}
