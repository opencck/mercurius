<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

/**
 * A server as an SSH entry of .mcp.json names it: the connection (context and name), the address, the login, the
 * group for the command policy and the server in between. The secrets are not here: they go to the process.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — host, port, login, group and route are fields of the entry
 */
final class SshTarget {
    public function __construct(
        /** context|name, as the connection is known */
        public readonly string $key,
        public readonly string $context,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $group = 'default',
        public readonly ?string $jumpHost = null,
        public readonly int $jumpPort = 22,
        public readonly ?string $jumpUser = null,
    ) {}

    public function address(): string {
        return self::join($this->host, $this->port);
    }

    public function jumpAddress(): ?string {
        return $this->jumpHost === null ? null : self::join($this->jumpHost, $this->jumpPort);
    }

    private static function join(string $host, int $port): string {
        return str_contains($host, ':') ? sprintf('[%s]:%d', $host, $port) : sprintf('%s:%d', $host, $port);
    }
}
