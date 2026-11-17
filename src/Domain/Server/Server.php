<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Server;

/**
 * A server of the owner the agent may work on: where it is, who logs in with which secret, its group for the command
 * policy and the host key the owner trusted.
 *
 * spec: BRIEF.md#фаза-7-по-частям
 */
final class Server {
    public const NAME_PATTERN = '~^[\p{L}\p{N}][\p{L}\p{N}._-]{0,63}$~u';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly ?int $secretId,
        public readonly string $group,
        /** SHA256:… the owner trusted; null until then */
        public readonly ?string $hostKey,
        /** seen at the first connection, waiting for the owner */
        public readonly ?string $pendingHostKey,
        /** null — directly; vless; secret:<name> — a proxy URI in the vault; via:<server> — through that server */
        public readonly ?string $route = null,
    ) {}

    public function address(): string {
        return str_contains($this->host, ':') ? sprintf('[%s]:%d', $this->host, $this->port) : sprintf('%s:%d', $this->host, $this->port);
    }
}
