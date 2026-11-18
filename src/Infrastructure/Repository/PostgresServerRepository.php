<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use UnexpectedValueException;

use function Mercurius\I18n\t;

final class PostgresServerRepository implements ServerRepository {
    private const COLUMNS = 'id, name, host, port, username, secret_id, server_group, host_key, pending_host_key, route';

    public function __construct(private readonly PostgresLink $db) {}

    public function add(string $name, string $host, int $port, string $username, ?int $secretId, string $group, ?string $route = null): Server {
        if ($this->byName($name) !== null) {
            throw new DomainException(sprintf(t('repository.postgres_server.server_uzhe_est'), $name));
        }
        try {
            $row = $this->db->execute(
                'INSERT INTO servers (name, host, port, username, secret_id, server_group, route) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING ' . self::COLUMNS,
                [$name, $host, $port, $username, $secretId, $group, $route],
            )->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_server.server_uzhe_est'), $name));
        }
        return self::server($row ?? throw new UnexpectedValueException('Server was not added'));
    }

    public function find(int $id): ?Server {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM servers WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function byName(string $name): ?Server {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM servers WHERE lower(name) = lower(?)', [$name])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . ' FROM servers ORDER BY lower(name)') as $row) {
            $found[] = self::server($row);
        }
        return $found;
    }

    public function setPendingHostKey(int $id, string $fingerprint): void {
        $this->db->execute('UPDATE servers SET pending_host_key = ? WHERE id = ? AND host_key IS NULL', [$fingerprint, $id]);
    }

    public function decideHostKey(int $id, bool $trust, ?string $fingerprint = null): ?Server {
        $row = $this->db->execute(
            ($trust
                ? 'UPDATE servers SET host_key = pending_host_key, pending_host_key = NULL WHERE id = ? AND pending_host_key IS NOT NULL'
                : 'UPDATE servers SET pending_host_key = NULL WHERE id = ? AND pending_host_key IS NOT NULL')
            . ' AND (?::text IS NULL OR pending_host_key = ?) RETURNING ' . self::COLUMNS,
            [$id, $fingerprint, $fingerprint],
        )->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function remove(int $id): bool {
        return $this->db->execute('DELETE FROM servers WHERE id = ? RETURNING id', [$id])->fetchRow() !== null;
    }

    /** @param array<string, mixed> $row */
    private static function server(array $row): Server {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $host = $row['host'] ?? null;
        $port = $row['port'] ?? null;
        $username = $row['username'] ?? null;
        $group = $row['server_group'] ?? null;
        if (!is_int($id) || !is_string($name) || !is_string($host) || !is_int($port) || !is_string($username) || !is_string($group)) {
            throw new UnexpectedValueException('Malformed server row');
        }
        return new Server(
            $id,
            $name,
            $host,
            $port,
            $username,
            isset($row['secret_id']) && is_int($row['secret_id']) ? $row['secret_id'] : null,
            $group,
            isset($row['host_key']) && is_string($row['host_key']) ? $row['host_key'] : null,
            isset($row['pending_host_key']) && is_string($row['pending_host_key']) ? $row['pending_host_key'] : null,
            isset($row['route']) && is_string($row['route']) ? $row['route'] : null,
        );
    }
}
