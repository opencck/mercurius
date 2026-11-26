<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Repository\PostgresSecretRepository;
use OpenCCK\Infrastructure\Repository\PostgresServerMove;
use OpenCCK\Infrastructure\Repository\PostgresServerRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the registry is read for the move: the secret by its name and
 * keeper, never its value; a moved server is not read again; the grants follow the new name
 *
 * @group integration
 */
final class PostgresServerMoveTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testTheServersAreReadWithTheirSecretsNameAndMarkedMoved(): void {
        [$id] = TestDatabase::conversation($this->db);
        $owner = new Member(max(1, $id), MemberKind::Human, Role::admin(), null);
        $suffix = bin2hex(random_bytes(3));
        $secret = (new Vault(new PostgresSecretRepository($this->db), random_bytes(32)))->put('mv-pass-' . $suffix, 'password', 'value-never-read', $owner);
        (new PostgresServerRepository($this->db))->add('mv-' . $suffix, '10.0.0.5', 2222, 'root', $secret, 'prod');
        $this->db->execute('INSERT INTO approval_grants (principal_id, key) VALUES (?, ?), (?, ?)', [$owner->id, 'ssh-any:mv-' . $suffix, $owner->id, 'ssh:v2:mv-' . $suffix . ':abc']);
        $move = new PostgresServerMove($this->db);

        $rows = array_values(array_filter($move->rows(), static fn(array $r): bool => $r['name'] === 'mv-' . $suffix));
        self::assertSame([['name' => 'mv-' . $suffix, 'host' => '10.0.0.5', 'port' => 2222, 'user' => 'root', 'group' => 'prod', 'route' => null, 'hostKey' => null, 'secret' => 'mv-pass-' . $suffix, 'kind' => 'password', 'holder' => $owner->id]], $rows);

        self::assertSame(2, $move->grants('mv-' . $suffix, 'mv_' . $suffix));
        $move->moved('mv-' . $suffix);

        self::assertSame([], array_values(array_filter($move->rows(), static fn(array $r): bool => $r['name'] === 'mv-' . $suffix)));
        $keys = [];
        foreach ($this->db->execute('SELECT key FROM approval_grants WHERE principal_id = ? AND key LIKE ? ORDER BY key', [$owner->id, '%mv_' . $suffix . '%']) as $row) {
            $keys[] = $row['key'];
        }
        self::assertSame(['ssh-any:mv_' . $suffix, 'ssh:v2:mv_' . $suffix . ':abc'], $keys);
    }
}
