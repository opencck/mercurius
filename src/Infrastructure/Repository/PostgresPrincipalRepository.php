<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;
use UnexpectedValueException;

final class PostgresPrincipalRepository implements PrincipalRepository {
    /** Advisory lock key: only one process creates the owner. */
    private const OWNER_LOCK = 7_331_002;

    public function __construct(private readonly PostgresLink $db) {}

    public function find(int $id): ?Principal {
        $row = $this->db->execute('SELECT id, kind, role, display_name, status FROM principals WHERE id = ?', [$id])->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByIdentity(string $channel, string $externalId): ?Principal {
        $row = $this->db->execute(
            'SELECT p.id, p.kind, p.role, p.display_name, p.status FROM channel_identities i JOIN principals p ON p.id = i.principal_id
             WHERE i.channel = ? AND i.external_id = ?',
            [$channel, $externalId],
        )->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Principal {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::OWNER_LOCK]);
            $row = $tx->query("SELECT id, kind, role, display_name, status FROM principals WHERE role = 'owner'")->fetchRow()
                ?? $tx->execute(
                    "INSERT INTO principals (kind, role, display_name) VALUES ('human', 'owner', ?) RETURNING id, kind, role, display_name, status",
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

    public function createVisitor(string $channel, string $externalId, ?string $displayName): Principal {
        $tx = $this->db->beginTransaction();
        try {
            // Serialises two first messages of the same stranger: one visitor, one card.
            $tx->execute('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::OWNER_LOCK, $channel . ':' . $externalId]);
            $row = $tx->execute(
                'SELECT p.id, p.kind, p.role, p.display_name, p.status FROM channel_identities i JOIN principals p ON p.id = i.principal_id
                 WHERE i.channel = ? AND i.external_id = ?',
                [$channel, $externalId],
            )->fetchRow() ?? $tx->execute(
                "INSERT INTO principals (kind, role, display_name, status) VALUES ('human', 'visitor', ?, 'pending')
                 RETURNING id, kind, role, display_name, status",
                [$displayName],
            )->fetchRow();
            if ($row === null) {
                throw new UnexpectedValueException('Visitor was not created');
            }
            $visitor = self::hydrate($row);
            $tx->execute(
                'INSERT INTO channel_identities (channel, external_id, principal_id) VALUES (?, ?, ?)
                 ON CONFLICT (channel, external_id) DO NOTHING',
                [$channel, $externalId, $visitor->id],
            );
            $tx->commit();
            return $visitor;
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    public function decideVisitor(int $id, Role $role, PrincipalStatus $status): ?Principal {
        $row = $this->db->execute(
            "UPDATE principals SET role = ?, status = ? WHERE id = ? AND status = 'pending'
             RETURNING id, kind, role, display_name, status",
            [$role->value, $status->value, $id],
        )->fetchRow();

        return $row === null ? null : self::hydrate($row);
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
        $status = isset($row['status']) && is_string($row['status']) ? PrincipalStatus::from($row['status']) : PrincipalStatus::Active;
        return new Principal($id, PrincipalKind::from($kind), Role::from($role), $name, $status);
    }
}
