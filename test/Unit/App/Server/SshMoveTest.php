<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use OpenCCK\App\Server\SshMove;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — a server of the former registry becomes an entry of .mcp.json
 * of whoever keeps its secret: host, port, login, group and route are fields of the entry, the secret is named in it
 */
final class SshMoveTest extends TestCase {
    /**
     * @param array{host?: string, port?: int, user?: string, group?: string, route?: ?string, hostKey?: ?string, secret?: ?string, kind?: ?string, holder?: ?int} $more
     * @return array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int}
     */
    private static function row(string $name, array $more = []): array {
        return $more + ['name' => $name, 'host' => '10.0.0.5', 'port' => 22, 'user' => 'root', 'group' => 'default', 'route' => null, 'hostKey' => 'SHA256:k', 'secret' => 'srv-' . $name . '-pass', 'kind' => 'password', 'holder' => 1];
    }

    public function testAServerBecomesAnEntryWithItsSecretInTheEnvironment(): void {
        $move = SshMove::of(self::row('xui-vps', ['holder' => 5, 'port' => 2222, 'group' => 'prod']), [], 'ssh');

        self::assertNotNull($move);
        self::assertSame(['member:5', 'xui_vps'], [$move->context, $move->name]);
        self::assertSame([
            'command' => 'php',
            'args' => ['/workspace/mcp/ssh/current/bin/ssh-mcp', '--host=10.0.0.5', '--user=root', '--port=2222', '--group=prod'],
            'env' => ['SSH_PASSWORD' => '{srv-xui-vps-pass}'],
        ], $move->entry);
        self::assertSame([['10.0.0.5:2222', 'SHA256:k']], $move->keys);
    }

    public function testAKeyLoginAndTheRoutesGoIntoTheEntry(): void {
        $bastion = self::row('bastion', ['host' => 'bastion.example', 'user' => 'ops', 'hostKey' => 'SHA256:b', 'secret' => 'b-key', 'kind' => 'private_key']);
        $inner = SshMove::of(self::row('inner', ['route' => 'via:bastion', 'kind' => 'private_key', 'secret' => 'i-key']), ['bastion' => $bastion], 'ssh');
        $far = SshMove::of(self::row('far', ['route' => 'vless']), [], 'ssh');
        $proxied = SshMove::of(self::row('proxied', ['route' => 'secret:my-proxy']), [], 'ssh');

        self::assertNotNull($inner);
        self::assertSame(['SSH_PRIVATE_KEY' => '{i-key}', 'SSH_JUMP_PRIVATE_KEY' => '{b-key}'], $inner->entry['env']);
        self::assertContains('--jump-host=bastion.example', $inner->entry['args']);
        self::assertContains('--jump-user=ops', $inner->entry['args']);
        self::assertSame([['10.0.0.5:22', 'SHA256:k'], ['bastion.example:22', 'SHA256:b']], $inner->keys);
        self::assertSame('socks5://vless-proxy:1080', $far?->entry['env']['SSH_PROXY'] ?? null, 'VLESS of the installation is its door');
        self::assertSame('{my-proxy}', $proxied?->entry['env']['SSH_PROXY'] ?? null);
    }

    public function testAServerWithoutASecretOrOwnerIsNotMoved(): void {
        self::assertNull(SshMove::of(self::row('nosecret', ['secret' => null]), [], 'ssh'));
        self::assertNull(SshMove::of(self::row('orphan', ['holder' => null]), [], 'ssh'));
        self::assertNull(SshMove::of(self::row('loop', ['route' => 'via:missing']), [], 'ssh'), 'a jump to a server that is not there');
    }

    public function testGrantsFollowTheNewName(): void {
        self::assertSame('ssh-any:xui_vps', SshMove::grant('ssh-any:xui-vps', 'xui-vps', 'xui_vps'));
        self::assertSame('ssh:v2:xui_vps:abc', SshMove::grant('ssh:v2:xui-vps:abc', 'xui-vps', 'xui_vps'));
        self::assertSame('sql-read:xui_vps:mysql:h:1:u:s', SshMove::grant('sql-read:xui-vps:mysql:h:1:u:s', 'xui-vps', 'xui_vps'));
        self::assertNull(SshMove::grant('ssh:v2:other:abc', 'xui-vps', 'xui_vps'));
        self::assertNull(SshMove::grant('http-secret:xui-vps x', 'xui-vps', 'xui_vps'), 'only grants of the server tools');
    }
}
