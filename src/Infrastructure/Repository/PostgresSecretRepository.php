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
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM secrets WHERE lower(name) = lower(?)', [$name])->fetchRow();
        return $row === null ? null : self::secret($row);
    }

    public function byId(int $id): ?array {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM secrets WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::secret($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT id, name, kind, principal_id FROM secrets ORDER BY lower(name)') as $row) {
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

    public function delete(string $name): bool {
        return $this->db->execute('DELETE FROM secrets WHERE lower(name) = lower(?) RETURNING id', [$name])->fetchRow() !== null;
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
}
