<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;
use UnexpectedValueException;

final class PostgresPrincipalRepository implements PrincipalRepository {
    /** Advisory lock key: only one process creates the owner. */
    private const OWNER_LOCK = 7_331_002;

    public function __construct(private readonly PostgresLink $db) {}

    public function find(int $id): ?Principal {
        $row = $this->db->execute('SELECT id, kind, role, display_name FROM principals WHERE id = ?', [$id])->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByIdentity(string $channel, string $externalId): ?Principal {
        $row = $this->db->execute(
            'SELECT p.id, p.kind, p.role, p.display_name FROM channel_identities i JOIN principals p ON p.id = i.principal_id
             WHERE i.channel = ? AND i.external_id = ?',
            [$channel, $externalId],
        )->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::OWNER_LOCK]);
            $row = $tx->query("SELECT id, kind, role, display_name FROM principals WHERE role = 'owner'")->fetchRow()
                ?? $tx->execute(
                    "INSERT INTO principals (kind, role, display_name) VALUES ('human', 'owner', ?) RETURNING id, kind, role, display_name",
                    [$displayName],
                )->fetchRow();
            if ($row === null) {
                throw new UnexpectedValueException('Owner was not created');
            }
            $owner = self::hydrate($row);
            $tx->execute(
                'INSERT INTO channel_identities (channel, external_id, principal_id) VALUES (?, ?, ?)
                 ON CONFLICT (channel, external_id) DO NOTHING',
                [$channel, $externalId, $owner->id],
            );
            $tx->commit();
            return $owner;
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Principal {
        $id = $row['id'] ?? null;
        $kind = $row['kind'] ?? null;
        $role = $row['role'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($kind) || !is_string($role)) {
            throw new UnexpectedValueException('Malformed principals row');
        }
        $name = isset($row['display_name']) && is_string($row['display_name']) ? $row['display_name'] : null;
        return new Principal($id, PrincipalKind::from($kind), Role::from($role), $name);
    }
}
