<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\MemberRepository;
use UnexpectedValueException;

final class PostgresMemberRepository implements MemberRepository {
    /** Advisory lock key: only one process creates the owner. */
    private const OWNER_LOCK = 7_331_002;

    public function __construct(private readonly PostgresLink $db) {}

    public function owner(): ?Member {
        $row = $this->db->query("SELECT id, kind, role, display_name, status FROM principals
             WHERE kind = 'human' AND status = 'active' AND role IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions))
             ORDER BY id = (SELECT to_principal FROM ownership_transfers ORDER BY at DESC, id DESC LIMIT 1) DESC NULLS LAST, id LIMIT 1")->fetchRow();
        return $row === null ? null : self::hydrate($row);
    }

    public function owners(): array {
        $found = [];
        foreach ($this->db->query("SELECT id, kind, role, display_name, status FROM principals
             WHERE kind = 'human' AND status = 'active' AND role IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions)) ORDER BY id") as $row) {
            $found[] = self::hydrate($row);
        }
        return $found;
    }

    public function countActivePeople(array $roles): int {
        if ($roles === []) {
            return 0;
        }
        $count = $this->db->execute(
            "SELECT count(*) AS n FROM principals WHERE kind = 'human' AND status = 'active' AND role = ANY(?::text[])",
            ['{' . implode(',', $roles) . '}'],
        )->fetchRow()['n'] ?? 0;
        return is_int($count) ? $count : 0;
    }

    public function addOwner(int $to): bool {
        // the first role of the list that has the permission owner: admin, unless the owners changed it
        $role = Role::ownerRoles()[0] ?? null;
        if ($role === null) {
            return false;
        }
        $row = $this->db->execute(
            "UPDATE principals SET role = ? WHERE id = ? AND kind = 'human' AND status = 'active' AND role NOT IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions))
             RETURNING id",
            [$role, $to],
        )->fetchRow();
        return $row !== null;
    }

    public function formerOwners(): array {
        $former = [];
        foreach ($this->db->query(
            "SELECT t.from_principal AS id, extract(epoch FROM max(t.at))::bigint AS until FROM ownership_transfers t
             JOIN principals p ON p.id = t.from_principal WHERE p.role NOT IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions)) GROUP BY t.from_principal",
        ) as $row) {
            $id = $row['id'] ?? null;
            $until = $row['until'] ?? null;
            if (is_int($id) && is_int($until)) {
                $former[$id] = $until;
            }
        }
        return $former;
    }

    public function find(int $id): ?Member {
        $row = $this->db->execute('SELECT id, kind, role, display_name, status FROM principals WHERE id = ?', [$id])->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByIdentity(string $channel, string $externalId): ?Member {
        $row = $this->db->execute(
            'SELECT p.id, p.kind, p.role, p.display_name, p.status FROM channel_identities i JOIN principals p ON p.id = i.principal_id
             WHERE i.channel = ? AND i.external_id = ?',
            [$channel, $externalId],
        )->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function externalId(int $principalId, string $channel): ?string {
        $row = $this->db->execute(
            'SELECT external_id FROM channel_identities WHERE principal_id = ? AND channel = ? ORDER BY external_id LIMIT 1',
            [$principalId, $channel],
        )->fetchRow();
        $id = $row['external_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function bindIdentity(int $principalId, string $channel, string $externalId): void {
        $this->db->execute('INSERT INTO channel_identities (channel, external_id, principal_id) VALUES (?, ?, ?) ON CONFLICT (channel, external_id) DO NOTHING', [$channel, $externalId, $principalId]);
    }

    public function ensureOwner(string $channel, string $externalId, ?string $displayName): Member {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::OWNER_LOCK]);
            $row = $tx->execute(
                'SELECT p.id, p.kind, p.role, p.display_name, p.status FROM channel_identities i JOIN principals p ON p.id = i.principal_id
                 WHERE i.channel = ? AND i.external_id = ?',
                [$channel, $externalId],
            )->fetchRow()
                ?? $tx->execute(
                    "INSERT INTO principals (kind, role, display_name) VALUES ('human', 'admin', ?) RETURNING id, kind, role, display_name, status",
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

    public function createAgent(string $name, Role $role): Member {
        // spec: brief/participants.md#свои-роли — an agent never holds a role with the permission owner
        if ($role->isOwner()) {
            throw new \InvalidArgumentException('An agent gets no owner role');
        }
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::OWNER_LOCK, 'a2a:' . $name]);
            $found = $tx->execute(
                'SELECT p.id FROM channel_identities i JOIN principals p ON p.id = i.principal_id WHERE i.channel = ? AND i.external_id = ?',
                ['a2a', $name],
            )->fetchRow();
            $row = isset($found['id']) && is_int($found['id'])
                ? $tx->execute(
                    "UPDATE principals SET role = ?, status = 'active', display_name = ? WHERE id = ? RETURNING id, kind, role, display_name, status",
                    [$role->value, $name, $found['id']],
                )->fetchRow()
                : $tx->execute(
                    "INSERT INTO principals (kind, role, display_name, status) VALUES ('agent', ?, ?, 'active') RETURNING id, kind, role, display_name, status",
                    [$role->value, $name],
                )->fetchRow();
            if ($row === null) {
                throw new UnexpectedValueException('Agent was not created');
            }
            $agent = self::hydrate($row);
            $tx->execute(
                'INSERT INTO channel_identities (channel, external_id, principal_id) VALUES (?, ?, ?) ON CONFLICT (channel, external_id) DO NOTHING',
                ['a2a', $name, $agent->id],
            );
            $tx->commit();
            return $agent;
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    public function createVisitor(string $channel, string $externalId, ?string $displayName): Member {
        $tx = $this->db->beginTransaction();
        try {
            // Serialises two first messages of the same stranger: one visitor, one card.
            $tx->execute('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::OWNER_LOCK, $channel . ':' . $externalId]);
            $row = $tx->execute(
                'SELECT p.id, p.kind, p.role, p.display_name, p.status FROM channel_identities i JOIN principals p ON p.id = i.principal_id
                 WHERE i.channel = ? AND i.external_id = ?',
                [$channel, $externalId],
            )->fetchRow() ?? $tx->execute(
                "INSERT INTO principals (kind, role, display_name, status) VALUES ('human', 'reader', ?, 'pending')
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

    public function decideVisitor(int $id, Role $role, MemberStatus $status): ?Member {
        $row = $this->db->execute(
            "UPDATE principals SET role = ?, status = ? WHERE id = ? AND status = 'pending'
             RETURNING id, kind, role, display_name, status",
            [$role->value, $status->value, $id],
        )->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function setRole(int $id, Role $role, MemberStatus $status): ?Member {
        $keeps = $role->isOwner() && $status === MemberStatus::Active;
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::OWNER_LOCK]);
            // spec: brief/participants.md#свои-роли — an agent is never an owner, and the last owner keeps the role
            $row = $tx->execute(
                "UPDATE principals SET role = ?, status = ? WHERE id = ? AND (kind = 'human' OR NOT ?::boolean)
                   AND (?::boolean OR NOT (kind = 'human' AND status = 'active' AND role IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions)))
                        OR EXISTS (SELECT 1 FROM principals o WHERE o.id <> ? AND o.kind = 'human' AND o.status = 'active'
                                   AND o.role IN (SELECT name FROM participant_roles WHERE 'owner' = ANY(permissions))))
                 RETURNING id, kind, role, display_name, status",
                [$role->value, $status->value, $id, $role->isOwner(), $keeps, $id],
            )->fetchRow();
            $tx->commit();
            return $row === null ? null : self::hydrate($row);
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT id, kind, role, display_name, status FROM principals ORDER BY id') as $row) {
            $found[] = self::hydrate($row);
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Member {
        $id = $row['id'] ?? null;
        $kind = $row['kind'] ?? null;
        $role = $row['role'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($kind) || !is_string($role)) {
            throw new UnexpectedValueException('Malformed principals row');
        }
        $name = isset($row['display_name']) && is_string($row['display_name']) ? $row['display_name'] : null;
        $status = isset($row['status']) && is_string($row['status']) ? MemberStatus::from($row['status']) : MemberStatus::Active;
        return new Member($id, MemberKind::from($kind), Role::from($role), $name, $status);
    }
}
