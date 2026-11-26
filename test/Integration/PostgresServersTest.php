<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Repository\PostgresCommandAuditRepository;
use OpenCCK\Infrastructure\Repository\PostgresSecretRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresServersTest extends AsyncTestCase {
    private PostgresConnection $db;
    private Member $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [$id] = TestDatabase::conversation($this->db);
        $this->owner = new Member(max(1, $id), MemberKind::Human, Role::admin(), null);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testSecretsRoundTripThroughByteaAndNamesAreUnique(): void {
        $vault = new Vault(new PostgresSecretRepository($this->db), random_bytes(32));
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\0binary\xff\n-----END OPENSSH PRIVATE KEY-----\n";

        $vault->put('Deploy-Key', 'private_key', $key, $this->owner);

        self::assertSame($key, $vault->reveal('deploy-key', $this->owner));
        self::assertSame([['name' => 'Deploy-Key', 'kind' => 'private_key']], $vault->names($this->owner));
        $this->expectException(\DomainException::class);
        $vault->put('deploy-KEY', 'password', 'x', $this->owner);
    }

    public function testTheJournalChainHoldsAndBreaksWhenChanged(): void {
        $audit = new PostgresCommandAuditRepository($this->db);
        $record = ['principal' => $this->owner->id, 'run' => null, 'server' => 'web', 'command' => 'uptime', 'class' => 'read', 'event' => 'start', 'exit' => null, 'ms' => null, 'error' => null];

        $first = $audit->append($record);
        $audit->append(['event' => 'done', 'exit' => 0, 'ms' => 12] + $record);
        $third = $audit->append(['command' => 'df -h', 'error' => 'нет «доступа»'] + $record);

        self::assertNull($audit->verify());
        self::assertSame(['df -h', 'uptime', 'uptime'], array_column($audit->recent(10), 'command'));
        // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the participant in /audit
        self::assertNotNull($audit->recent(1)[0]['who'] ?? null);
        $this->db->execute('UPDATE command_audit SET command = ? WHERE id = ?', ['rm -rf /tmp/x', $third]);
        self::assertSame($third, $audit->verify());
        $this->db->execute('DELETE FROM command_audit WHERE id = ?', [$first]);
        self::assertNotNull($audit->verify(), 'a removed record breaks the chain too');
    }
}
