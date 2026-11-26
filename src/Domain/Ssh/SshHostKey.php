<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Ssh;

/**
 * The host key of one address of an SSH entry: the one the owner trusted, and one seen that waits for the owner.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp
 */
final class SshHostKey {
    public function __construct(
        public readonly int $id,
        /** the connection: context|name */
        public readonly string $server,
        /** host:port, [v6]:port */
        public readonly string $address,
        public readonly ?string $hostKey,
        public readonly ?string $pending,
    ) {}
}
