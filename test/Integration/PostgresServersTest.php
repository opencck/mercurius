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
use OpenCCK\Infrastructure\Repository\PostgresServerRepository;
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
        $this->owner = new Member(max(1, $id), MemberKind::Human, Role::owner(), null);
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

    public function testServersAndTheirHostKeys(): void {
        $servers = new PostgresServerRepository($this->db);
        $server = $servers->add('Web', '2001:db8::5', 2222, 'deploy', null, 'prod');

        self::assertSame('[2001:db8::5]:2222', $server->address());
        $servers->setPendingHostKey($server->id, 'SHA256:first');
        self::assertSame('SHA256:first', $servers->byName('web')?->pendingHostKey);
        self::assertSame('SHA256:first', $servers->decideHostKey($server->id, true)?->hostKey);
        self::assertNull($servers->decideHostKey($server->id, true), 'decided once');
        $servers->setPendingHostKey($server->id, 'SHA256:other');
        self::assertNull($servers->find($server->id)?->pendingHostKey, 'a trusted key is not replaced by a pending one');
        // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a changed field keeps the key, a new address forgets it
        $changed = $servers->update($server->id, new \OpenCCK\Domain\Server\ServerChanges(group: 'test', route: 'vless'));
        self::assertSame(['test', 'vless', 'SHA256:first'], [$changed?->group, $changed?->route, $changed?->hostKey]);
        $moved = $servers->update($server->id, new \OpenCCK\Domain\Server\ServerChanges(host: '2001:db8::6', route: ''));
        self::assertSame(['2001:db8::6', null, null], [$moved?->host, $moved?->route, $moved?->hostKey]);
        self::assertTrue($servers->remove($server->id));
        self::assertSame([], $servers->all());
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
