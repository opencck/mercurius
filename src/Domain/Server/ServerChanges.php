<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Server;

/**
 * What changes in a server of the registry; null — stays. A new host or port, or forgetHostKey, forgets the trusted
 * host key and the one waiting for the owner: the next connection asks again.
 *
 * spec: BRIEF.md#фаза-7-по-частям — the registry of servers
 */
final class ServerChanges {
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $username = null,
        public readonly ?int $secretId = null,
        public readonly ?string $group = null,
        /** the new route: "" — directly */
        public readonly ?string $route = null,
        public readonly bool $forgetHostKey = false,
    ) {}

    public function isEmpty(): bool {
        return $this->name === null && $this->host === null && $this->port === null && $this->username === null
            && $this->secretId === null && $this->group === null && $this->route === null && !$this->forgetHostKey;
    }

    /** Whether the trusted host key is forgotten for a server as it is now. */
    public function forgetsKey(Server $server): bool {
        return $this->forgetHostKey
            || ($this->host !== null && $this->host !== $server->host)
            || ($this->port !== null && $this->port !== $server->port);
    }
}
