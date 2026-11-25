<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\Postgres\PostgresLink;

/**
 * Moves a running instance from the single owner to roles with the permission owner. The member of the account from
 * the configuration and whoever has the old role owner become administrators (nobody loses the agent if ownership
 * was passed on); the other administrators become users — an administrator is an owner now. Once no member has the
 * old role, it changes nothing.
 *
 * spec: brief/participants.md#свои-роли — the move of running instances
 */
final class OwnerRoleUpgrade {
    /** the advisory lock of the members' roles (PostgresMemberRepository::OWNER_LOCK) */
    private const LOCK = 7_331_002;

    public function __construct(private readonly PostgresLink $db) {}

    /** @return int how many members got another role */
    public function run(string $channel, string $adminExternalId): int {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            if ($tx->query("SELECT 1 FROM principals WHERE role = 'owner' LIMIT 1")->fetchRow() === null) {
                $tx->commit();
                return 0;
            }
            $keep = "role = 'owner' OR id IN (SELECT principal_id FROM channel_identities WHERE channel = ? AND external_id = ?)";
            $users = $tx->execute("UPDATE principals SET role = 'user' WHERE role = 'admin' AND NOT ($keep)", [$channel, $adminExternalId])->getRowCount() ?? 0;
            $admins = $tx->execute("UPDATE principals SET role = 'admin' WHERE kind = 'human' AND role <> 'admin' AND ($keep)", [$channel, $adminExternalId])->getRowCount() ?? 0;
            $tx->commit();
            return $users + $admins;
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }
}
