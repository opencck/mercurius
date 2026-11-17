<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Socket\SocketConnector;
use OpenCCK\Domain\Server\Server;
use SensitiveParameter;

/**
 * How to reach a server: its login, the socket route (null — directly) and, for a jump, the server in between.
 *
 * spec: BRIEF.md#фаза-7-по-частям — directly, through a proxy, through VLESS, through another server
 */
final class Connection {
    public readonly string $credential;

    public function __construct(
        public readonly Server $server,
        #[SensitiveParameter] string $credential,
        /** password or private_key */
        public readonly string $kind,
        public readonly ?SocketConnector $connector = null,
        public readonly ?Connection $via = null,
    ) {
        $this->credential = $credential;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array {
        return ['server' => $this->server->name, 'credential' => '***', 'kind' => $this->kind];
    }
}
