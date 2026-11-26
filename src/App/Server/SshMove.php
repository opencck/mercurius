<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use OpenCCK\Domain\Mcp\McpServer;

/**
 * A server of the former registry as an entry of .mcp.json: in the file of whoever keeps its secret, named by the
 * server (in the letters of an entry), the secret named in the environment of the process, the route as its fields;
 * the host keys the owner trusted go with it.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — servers of the registry become entries, keys and grants follow
 */
final class SshMove {
    /** the door of the installation's way out: VLESS of the installation, as a command of the sandbox reaches it */
    public const DOOR = 'socks5://vless-proxy:1080';
    /** the grants of the tools of servers, which name the server second */
    private const GRANTS = '~^(ssh(?:-any|-never|-sudo)?|sql-read|sql-write|file-write(?:-never)?):((?:v\d+:)?)(.+?)(:.*)?$~';

    /**
     * @param array{command: string, args: list<string>, env: array<string, string>} $entry
     * @param list<array{string, string}> $keys address and trusted key
     */
    private function __construct(
        public readonly string $context,
        public readonly string $name,
        public readonly array $entry,
        public readonly array $keys,
    ) {}

    /**
     * @param array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int} $row
     * @param array<string, array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int}> $byName the other servers, for a jump
     * @return self|null null — nothing to log in with, nobody keeps it, or its jump is not there
     */
    public static function of(array $row, array $byName, string $install): ?self {
        if ($row['secret'] === null || $row['holder'] === null) {
            return null;
        }
        $args = ['/workspace/mcp/' . $install . '/current/bin/ssh-mcp', '--host=' . $row['host'], '--user=' . $row['user'], '--port=' . $row['port'], '--group=' . $row['group']];
        $env = [self::variable($row['kind'], '') => '{' . $row['secret'] . '}'];
        $keys = $row['hostKey'] === null ? [] : [[self::address($row['host'], $row['port']), $row['hostKey']]];
        $route = $row['route'];
        if ($route === 'vless') {
            $env['SSH_PROXY'] = self::DOOR;
        } elseif ($route !== null && str_starts_with($route, 'secret:')) {
            $env['SSH_PROXY'] = '{' . substr($route, 7) . '}';
        } elseif ($route !== null && str_starts_with($route, 'via:')) {
            $jump = $byName[mb_strtolower(substr($route, 4))] ?? null;
            if ($jump === null || $jump['secret'] === null) {
                return null;
            }
            array_push($args, '--jump-host=' . $jump['host'], '--jump-user=' . $jump['user'], '--jump-port=' . $jump['port']);
            $env[self::variable($jump['kind'], 'JUMP_')] = '{' . $jump['secret'] . '}';
            if ($jump['route'] === 'vless') {
                $env['SSH_PROXY'] = self::DOOR;
            } elseif ($jump['route'] !== null && str_starts_with($jump['route'], 'secret:')) {
                $env['SSH_PROXY'] = '{' . substr($jump['route'], 7) . '}';
            }
            if ($jump['hostKey'] !== null) {
                $keys[] = [self::address($jump['host'], $jump['port']), $jump['hostKey']];
            }
        }
        return new self('member:' . $row['holder'], self::name($row['name']), ['command' => 'php', 'args' => $args, 'env' => $env], $keys);
    }

    /** The name of the entry: the server's, in the letters an entry takes. */
    public static function name(string $server): string {
        $name = trim(preg_replace('~[^a-z0-9_]+~', '_', mb_strtolower($server)) ?? '', '_');
        $name = substr($name === '' ? 'server' : $name, 0, 24);
        return preg_match(McpServer::NAME_PATTERN, $name) === 1 ? $name : 's' . substr($name, 0, 23);
    }

    /** A grant of a server tool with the new name of its server; null — not a grant of this server. */
    public static function grant(string $key, string $from, string $to): ?string {
        if (preg_match(self::GRANTS, $key, $m) !== 1 || mb_strtolower($m[3]) !== mb_strtolower($from)) {
            return null;
        }
        return $m[1] . ':' . $m[2] . $to . ($m[4] ?? '');
    }

    private static function variable(?string $kind, string $jump): string {
        return 'SSH_' . $jump . ($kind === 'private_key' ? 'PRIVATE_KEY' : 'PASSWORD');
    }

    private static function address(string $host, int $port): string {
        return str_contains($host, ':') ? sprintf('[%s]:%d', $host, $port) : sprintf('%s:%d', $host, $port);
    }
}
