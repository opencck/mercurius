<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresByteA;
use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Repository\SecretRepository;
use UnexpectedValueException;

use function Mercurius\I18n\t;

final class PostgresSecretRepository implements SecretRepository {
    private const COLUMNS = "id, name, kind, encode(nonce, 'base64') AS nonce, encode(ciphertext, 'base64') AS ciphertext, principal_id";

    public function __construct(private readonly PostgresLink $db) {}

    public function store(string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int {
        if ($this->find($name) !== null) {
            throw new DomainException(sprintf(t('repository.postgres_secret.sekret_uzhe_est'), $name));
        }
        try {
            $row = $this->db->execute(
                'INSERT INTO secrets (name, kind, nonce, ciphertext, principal_id) VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$name, $kind, new PostgresByteA($nonce), new PostgresByteA($ciphertext), $principalId],
            )->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_secret.sekret_uzhe_est'), $name));
        }
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : throw new UnexpectedValueException('Secret was not stored');
    }

    public function find(string $name): ?array {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM secrets WHERE lower(name) = lower(?) AND project_id IS NULL AND NOT agent', [$name])->fetchRow();
        return $row === null ? null : self::secret($row);
    }

    public function byId(int $id): ?array {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM secrets WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::secret($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT id, name, kind, principal_id FROM secrets WHERE project_id IS NULL AND NOT agent ORDER BY lower(name)') as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            $kind = $row['kind'] ?? null;
            $principal = $row['principal_id'] ?? null;
            if (is_int($id) && is_string($name) && is_string($kind)) {
                $found[] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'principal' => is_int($principal) ? $principal : null];
            }
        }
        return $found;
    }

    public function replace(string $name, string $kind, string $nonce, string $ciphertext): bool {
        return $this->db->execute(
            'UPDATE secrets SET kind = ?, nonce = ?, ciphertext = ? WHERE lower(name) = lower(?) AND project_id IS NULL AND NOT agent RETURNING id',
            [$kind, new PostgresByteA($nonce), new PostgresByteA($ciphertext), $name],
        )->fetchRow() !== null;
    }

    public function delete(string $name): bool {
        return $this->db->execute('DELETE FROM secrets WHERE lower(name) = lower(?) AND project_id IS NULL AND NOT agent RETURNING id', [$name])->fetchRow() !== null;
    }

    public function storeIn(int $projectId, string $site, string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int {
        if ($this->findIn($name, $projectId) !== null) {
            throw new DomainException(sprintf(t('repository.postgres_secret.sekret_uzhe_est'), $name));
        }
        try {
            $row = $this->db->execute(
                'INSERT INTO secrets (name, kind, nonce, ciphertext, principal_id, project_id, site) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$name, $kind, new PostgresByteA($nonce), new PostgresByteA($ciphertext), $principalId, $projectId, $site],
            )->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_secret.sekret_uzhe_est'), $name));
        }
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : throw new UnexpectedValueException('Secret was not stored');
    }

    public function findIn(string $name, int $projectId): ?array {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ', site FROM secrets WHERE lower(name) = lower(?) AND project_id = ?', [$name, $projectId])->fetchRow();
        if ($row === null) {
            return null;
        }
        $site = $row['site'] ?? null;
        return self::secret($row) + ['site' => is_string($site) ? $site : ''];
    }

    public function ofProject(int $projectId): array {
        $found = [];
        foreach ($this->db->execute('SELECT name, kind, site FROM secrets WHERE project_id = ? ORDER BY lower(name)', [$projectId]) as $row) {
            $name = $row['name'] ?? null;
            $kind = $row['kind'] ?? null;
            $site = $row['site'] ?? null;
            if (is_string($name) && is_string($kind)) {
                $found[] = ['name' => $name, 'kind' => $kind, 'site' => is_string($site) ? $site : ''];
            }
        }
        return $found;
    }

    public function replaceIn(string $name, int $projectId, string $site, string $kind, string $nonce, string $ciphertext): bool {
        return $this->db->execute(
            'UPDATE secrets SET kind = ?, nonce = ?, ciphertext = ?, site = ? WHERE lower(name) = lower(?) AND project_id = ? RETURNING id',
            [$kind, new PostgresByteA($nonce), new PostgresByteA($ciphertext), $site, $name, $projectId],
        )->fetchRow() !== null;
    }

    public function deleteIn(string $name, int $projectId): bool {
        return $this->db->execute('DELETE FROM secrets WHERE lower(name) = lower(?) AND project_id = ? RETURNING id', [$name, $projectId])->fetchRow() !== null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}
     */
    private static function secret(array $row): array {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $kind = $row['kind'] ?? null;
        $nonce = $row['nonce'] ?? null;
        $ciphertext = $row['ciphertext'] ?? null;
        if (!is_int($id) || !is_string($name) || !is_string($kind) || !is_string($nonce) || !is_string($ciphertext)) {
            throw new UnexpectedValueException('Malformed secret row');
        }
        return [
            'id' => $id,
            'name' => $name,
            'kind' => $kind,
            'nonce' => (string) base64_decode(str_replace("\n", '', $nonce), true),
            'ciphertext' => (string) base64_decode(str_replace("\n", '', $ciphertext), true),
            'principal' => isset($row['principal_id']) && is_int($row['principal_id']) ? $row['principal_id'] : null,
        ];
    }

    public function findAgent(string $name): ?array {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM secrets WHERE lower(name) = lower(?) AND agent', [$name])->fetchRow();
        return $row === null ? null : self::secret($row);
    }

    public function storeAgent(string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int {
        try {
            $row = $this->db->execute(
                'INSERT INTO secrets (name, kind, nonce, ciphertext, principal_id, agent) VALUES (?, ?, ?, ?, ?, true) RETURNING id',
                [$name, $kind, new PostgresByteA($nonce), new PostgresByteA($ciphertext), $principalId],
            )->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_secret.sekret_uzhe_est'), $name));
        }
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : throw new UnexpectedValueException('Secret was not stored');
    }

    public function replaceAgent(string $name, string $kind, string $nonce, string $ciphertext): bool {
        return $this->db->execute(
            'UPDATE secrets SET kind = ?, nonce = ?, ciphertext = ? WHERE lower(name) = lower(?) AND agent RETURNING id',
            [$kind, new PostgresByteA($nonce), new PostgresByteA($ciphertext), $name],
        )->fetchRow() !== null;
    }

    public function deleteAgent(string $name): bool {
        return $this->db->execute('DELETE FROM secrets WHERE lower(name) = lower(?) AND agent RETURNING id', [$name])->fetchRow() !== null;
    }

    public function ofAgent(): array {
        $found = [];
        foreach ($this->db->query('SELECT name, kind FROM secrets WHERE agent ORDER BY lower(name)') as $row) {
            $name = $row['name'] ?? null;
            $kind = $row['kind'] ?? null;
            if (is_string($name) && is_string($kind)) {
                $found[] = ['name' => $name, 'kind' => $kind];
            }
        }
        return $found;
    }
}
