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
    /** the vault name of the agent's own key for other agents (AgentIdentity::VAULT_NAME), and of its extra keys */
    private const AGENT_KEY = 'a2a-identity-key';

    /** @param string $channel the channel of the owner's chat: jobs passed with the agent go to the new owner's chat there */
    public function __construct(private readonly PostgresLink $db, private readonly string $channel) {}

    public function owner(): ?Principal {
        $row = $this->db->query("SELECT id, kind, role, display_name, status FROM principals WHERE role = 'owner'")->fetchRow();
        return $row === null ? null : self::hydrate($row);
    }

    public function transferOwnership(int $from, int $to): bool {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::OWNER_LOCK]);
            $old = $tx->execute("SELECT id FROM principals WHERE id = ? AND role = 'owner'", [$from])->fetchRow();
            $new = $tx->execute("SELECT id FROM principals WHERE id = ? AND id <> ? AND kind = 'human' AND status = 'active'", [$to, $from])->fetchRow();
            if ($old === null || $new === null) {
                return false;
            }
            $tx->execute("UPDATE principals SET role = 'admin' WHERE id = ?", [$from]);
            $tx->execute("UPDATE principals SET role = 'owner' WHERE id = ?", [$to]);
            // the personal stays with the former owner: memory about them, the projects they had as owner
            $tx->execute("UPDATE facts SET scope = 'principal:' || ? WHERE scope = 'owner'", [(string) $from]);
            $tx->execute('UPDATE projects SET owner_id = ? WHERE owner_id IS NULL', [$from]);
            // what the agent has goes with it: its key for other agents and the jobs it keeps for the owner
            $tx->execute(
                "UPDATE secrets SET principal_id = ? WHERE lower(name) = ? OR starts_with(lower(name), ?)",
                [$to, self::AGENT_KEY, self::AGENT_KEY . '-'],
            );
            $tx->execute(
                "UPDATE jobs SET principal_id = ?, chat_id = coalesce((SELECT external_id FROM channel_identities WHERE principal_id = ? AND channel = ? ORDER BY external_id LIMIT 1), chat_id)
                 WHERE principal_id = ? AND title IN ('initiative', 'self-review')",
                [$to, $to, $this->channel, $from],
            );
            $tx->execute('INSERT INTO ownership_transfers (from_principal, to_principal) VALUES (?, ?)', [$from, $to]);
            $tx->commit();
            return true;
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    public function formerOwners(): array {
        $former = [];
        foreach ($this->db->query(
            "SELECT t.from_principal AS id, extract(epoch FROM max(t.at))::bigint AS until FROM ownership_transfers t
             JOIN principals p ON p.id = t.from_principal WHERE p.role <> 'owner' GROUP BY t.from_principal",
        ) as $row) {
            $id = $row['id'] ?? null;
            $until = $row['until'] ?? null;
            if (is_int($id) && is_int($until)) {
                $former[$id] = $until;
            }
        }
        return $former;
    }

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

    public function createAgent(string $name, Role $role): Principal {
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

    public function setRole(int $id, Role $role, PrincipalStatus $status): ?Principal {
        $row = $this->db->execute(
            "UPDATE principals SET role = ?, status = ? WHERE id = ? AND role <> 'owner'
             RETURNING id, kind, role, display_name, status",
            [$role->value, $status->value, $id],
        )->fetchRow();

        return $row === null ? null : self::hydrate($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT id, kind, role, display_name, status FROM principals ORDER BY id') as $row) {
            $found[] = self::hydrate($row);
        }
        return $found;
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
