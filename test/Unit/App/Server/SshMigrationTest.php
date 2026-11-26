<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\App\Server\SshMigration;
use OpenCCK\Test\Support\InMemorySshHostKeyRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the servers of the registry become entries once, with their
 * host keys and grants; with no MCP installed yet they wait
 */
final class SshMigrationTest extends TestCase {
    /** @var list<string> */
    private array $done = [];
    private InMemorySshHostKeyRepository $keys;

    protected function setUp(): void {
        $this->keys = new InMemorySshHostKeyRepository();
    }

    /** @param list<array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int}> $rows */
    private function migration(array $rows, ?string $install, string $approval = 'ok'): SshMigration {
        return new SshMigration(
            static fn(): array => $rows,
            static fn(): ?string => $install,
            function (string $install, string $context): void {
                $this->done[] = "allow $install $context";
            },
            function (string $context, McpEntry $entry): void {
                $this->done[] = "put $context {$entry->name}";
            },
            function (string $context, string $name) use ($approval): ?string {
                $this->done[] = "approve $context $name";
                return $approval === 'ok' ? null : $approval;
            },
            function (string $from, string $to): int {
                $this->done[] = "grants $from $to";
                return 2;
            },
            function (string $server): void {
                $this->done[] = "moved $server";
            },
            $this->keys,
            new NullLogger(),
        );
    }

    /** @return array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int} */
    private static function row(string $name, ?int $holder = 1): array {
        return ['name' => $name, 'host' => 'h-' . $name, 'port' => 22, 'user' => 'root', 'group' => 'default', 'route' => null, 'hostKey' => 'SHA256:' . $name, 'secret' => $name . '-pass', 'kind' => 'password', 'holder' => $holder];
    }

    public function testEachServerBecomesAnApprovedEntryWithItsKeyAndGrants(): void {
        $lines = $this->migration([self::row('base'), self::row('xui-vps', 5)], 'ssh')->run();

        self::assertSame([
            'allow ssh member:1', 'put member:1 base', 'approve member:1 base', 'grants base base', 'moved base',
            'allow ssh member:5', 'put member:5 xui_vps', 'approve member:5 xui_vps', 'grants xui-vps xui_vps', 'moved xui-vps',
        ], $this->done);
        self::assertSame('SHA256:xui-vps', $this->keys->of('member:5|xui_vps', 'h-xui-vps:22')?->hostKey, 'no new host key card after the move');
        self::assertCount(2, $lines);
    }

    public function testWithoutTheMcpInstalledNothingMoves(): void {
        $lines = $this->migration([self::row('base')], null)->run();

        self::assertSame([], $this->done);
        self::assertCount(1, $lines, 'the owner is told the servers wait');
    }

    public function testAnEntryThatWasNotApprovedIsNotMarkedMoved(): void {
        $this->migration([self::row('base'), self::row('orphan', null)], 'ssh', 'no answer')->run();

        self::assertNotContains('moved base', $this->done);
        self::assertNotContains('put member:1 orphan', $this->done);
        self::assertNull($this->keys->of('member:1|base', 'h-base:22'), 'the key waits for the entry');
    }
}
