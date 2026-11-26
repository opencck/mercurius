<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SshHostKeyRepository;
use OpenCCK\Domain\Ssh\SshHostKey;
use UnexpectedValueException;

/** spec: brief/servers.md#ssh-как-предустановленный-mcp — the host keys of SSH entries */
final class PostgresSshHostKeyRepository implements SshHostKeyRepository {
    private const COLUMNS = 'id, server, address, host_key, pending_host_key';

    public function __construct(private readonly PostgresLink $db) {}

    public function of(string $server, string $address): ?SshHostKey {
        return self::row($this->db->execute('SELECT ' . self::COLUMNS . ' FROM ssh_host_keys WHERE server = ? AND address = ?', [$server, $address])->fetchRow());
    }

    public function find(int $id): ?SshHostKey {
        return self::row($this->db->execute('SELECT ' . self::COLUMNS . ' FROM ssh_host_keys WHERE id = ?', [$id])->fetchRow());
    }

    public function setPending(string $server, string $address, string $fingerprint): SshHostKey {
        $row = self::row($this->db->execute(
            'INSERT INTO ssh_host_keys (server, address, pending_host_key) VALUES (?, ?, ?)
             ON CONFLICT (server, address) DO UPDATE SET pending_host_key = excluded.pending_host_key, updated_at = now()
             RETURNING ' . self::COLUMNS,
            [$server, $address, $fingerprint],
        )->fetchRow());
        return $row ?? throw new UnexpectedValueException('The host key was not kept');
    }

    public function decide(int $id, bool $trust, string $fingerprint): ?SshHostKey {
        return self::row($this->db->execute(
            'UPDATE ssh_host_keys SET host_key = CASE WHEN ? THEN pending_host_key ELSE host_key END, pending_host_key = NULL,
             updated_at = now() WHERE id = ? AND pending_host_key = ? RETURNING ' . self::COLUMNS,
            [$trust, $id, $fingerprint],
        )->fetchRow());
    }

    public function forget(int $id): ?SshHostKey {
        return self::row($this->db->execute('UPDATE ssh_host_keys SET host_key = NULL, pending_host_key = NULL, updated_at = now() WHERE id = ? RETURNING ' . self::COLUMNS, [$id])->fetchRow());
    }

    public function pin(string $server, string $address, string $hostKey): void {
        $this->db->execute(
            'INSERT INTO ssh_host_keys (server, address, host_key) VALUES (?, ?, ?)
             ON CONFLICT (server, address) DO UPDATE SET host_key = excluded.host_key, pending_host_key = NULL, updated_at = now()',
            [$server, $address, $hostKey],
        );
    }

    /** @param array<string, mixed>|null $row */
    private static function row(?array $row): ?SshHostKey {
        if ($row === null) {
            return null;
        }
        [$id, $server, $address] = [$row['id'] ?? null, $row['server'] ?? null, $row['address'] ?? null];
        if (!is_int($id) || !is_string($server) || !is_string($address)) {
            throw new UnexpectedValueException('Malformed host key row');
        }
        $key = isset($row['host_key']) && is_string($row['host_key']) ? $row['host_key'] : null;
        $pending = isset($row['pending_host_key']) && is_string($row['pending_host_key']) ? $row['pending_host_key'] : null;
        return new SshHostKey($id, $server, $address, $key, $pending);
    }
}
