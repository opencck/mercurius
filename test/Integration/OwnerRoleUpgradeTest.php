<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\App\Service\OwnerRoleUpgrade;
use OpenCCK\Test\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A running instance moves to roles with the permission owner: the member of TELEGRAM_ADMIN_ID and the owner keep
 * the agent as administrators, the other administrators become users.
 *
 * spec: brief/participants.md#свои-роли
 *
 * @group integration
 */
final class OwnerRoleUpgradeTest extends TestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        $this->db = TestDatabase::connect();
    }

    private function member(string $role, ?string $telegram = null): int {
        $id = $this->db->execute("INSERT INTO principals (kind, role, display_name) VALUES ('human', ?, ?) RETURNING id", [$role, $role])->fetchRow()['id'] ?? null;
        self::assertIsInt($id);
        if ($telegram !== null) {
            $this->db->execute("INSERT INTO channel_identities (channel, external_id, principal_id) VALUES ('telegram', ?, ?)", [$telegram, $id]);
        }
        return $id;
    }

    private function role(int $id): ?string {
        $role = $this->db->execute('SELECT role FROM principals WHERE id = ?', [$id])->fetchRow()['role'] ?? null;
        return is_string($role) ? $role : null;
    }

    public function testEnvAdminAndCurrentOwnerBecomeAdmins(): void {
        $owner = $this->member('owner', '100');
        $admin = $this->member('admin');
        $user = $this->member('user');
        $reader = $this->member('reader');

        self::assertSame(2, (new OwnerRoleUpgrade($this->db))->run('telegram', '100'));

        self::assertSame(['admin', 'user', 'user', 'reader'], array_map($this->role(...), [$owner, $admin, $user, $reader]));
    }

    public function testTransferredOwnershipKeepsBoth(): void {
        $env = $this->member('admin', '100');
        $owner = $this->member('owner', '200');

        (new OwnerRoleUpgrade($this->db))->run('telegram', '100');

        self::assertSame(['admin', 'admin'], [$this->role($env), $this->role($owner)]);
    }

    public function testSecondRunChangesNothing(): void {
        $this->member('owner', '100');
        $this->member('admin');
        $upgrade = new OwnerRoleUpgrade($this->db);
        $upgrade->run('telegram', '100');

        self::assertSame(0, $upgrade->run('telegram', '100'));
    }

    public function testTheVisitorRoleIsNowReader(): void {
        $roles = $this->db->query("SELECT name, permissions::text AS p FROM participant_roles WHERE name IN ('admin', 'reader', 'visitor') ORDER BY name");
        $found = [];
        foreach ($roles as $row) {
            $found[] = (is_string($row['name']) ? $row['name'] : '') . ':' . (is_string($row['p']) ? $row['p'] : '');
        }

        self::assertSame(['admin:{owner}', 'reader:{}'], $found);
    }
}
