<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Domain\Ssh\SshKey;
use UnexpectedValueException;

/** spec: brief/servers.md#ключи-ssh-в-песочнице — the account of the keys in a participant's ~/.ssh */
final class PostgresSshKeyRepository implements SshKeyRepository {
    private const COLUMNS = 'holder, name, fingerprint, public_key, passphrase_secret';

    public function __construct(private readonly PostgresLink $db) {}

    public function of(int $holder): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM ssh_keys WHERE holder = ? ORDER BY name', [$holder]) as $row) {
            $found[] = self::key($row);
        }
        return $found;
    }

    public function find(int $holder, string $name): ?SshKey {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM ssh_keys WHERE holder = ? AND name = ?', [$holder, $name])->fetchRow();
        return $row === null ? null : self::key($row);
    }

    public function save(SshKey $key): void {
        $this->db->execute(
            'INSERT INTO ssh_keys (holder, name, fingerprint, public_key, passphrase_secret) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (holder, name) DO UPDATE SET fingerprint = excluded.fingerprint, public_key = excluded.public_key,
             passphrase_secret = coalesce(ssh_keys.passphrase_secret, excluded.passphrase_secret)',
            [$key->holder, $key->name, $key->fingerprint, $key->publicKey, $key->passphraseSecret],
        );
    }

    public function setPassphrase(int $holder, string $name, ?string $secret): bool {
        return $this->db->execute('UPDATE ssh_keys SET passphrase_secret = ? WHERE holder = ? AND name = ? RETURNING name', [$secret, $holder, $name])->fetchRow() !== null;
    }

    public function remove(int $holder, string $name): bool {
        return $this->db->execute('DELETE FROM ssh_keys WHERE holder = ? AND name = ? RETURNING name', [$holder, $name])->fetchRow() !== null;
    }

    /** @param array<string, mixed> $row */
    private static function key(array $row): SshKey {
        [$holder, $name, $fingerprint, $public] = [$row['holder'] ?? null, $row['name'] ?? null, $row['fingerprint'] ?? null, $row['public_key'] ?? null];
        if (!is_int($holder) || !is_string($name) || !is_string($fingerprint) || !is_string($public)) {
            throw new UnexpectedValueException('Malformed SSH key row');
        }
        $secret = isset($row['passphrase_secret']) && is_string($row['passphrase_secret']) ? $row['passphrase_secret'] : null;
        return new SshKey($holder, $name, $fingerprint, $public, $secret);
    }
}
