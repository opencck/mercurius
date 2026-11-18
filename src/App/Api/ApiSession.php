<?php

declare(strict_types=1);

namespace OpenCCK\App\Api;

use Mercurius\Messenger\Websocket\Connection;
use OpenCCK\Domain\Entity\Principal;

/** One open connection of the WebSocket API: whose it is, its chat and the key that detaches it. */
final class ApiSession {
    public function __construct(
        public readonly Principal $principal,
        public readonly string $chatId,
        public readonly string $userId,
        public readonly int $key,
        public readonly Connection $connection,
    ) {}
}
