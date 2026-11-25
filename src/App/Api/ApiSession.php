<?php

declare(strict_types=1);

namespace OpenCCK\App\Api;

use Mercurius\Messenger\Websocket\Connection;
use OpenCCK\Domain\Entity\Member;

/** One open connection of the WebSocket API: whose it is, its chat and the key that detaches it. */
final class ApiSession {
    public function __construct(
        public readonly Member $principal,
        public readonly string $chatId,
        public readonly string $userId,
        public readonly int $key,
        public readonly Connection $connection,
        /** the number of the API key the connection came with; 0 — not tied to a key */
        public readonly int $apiKey = 0,
        /** @var (\Closure(): void)|null closes the connection */
        public readonly ?\Closure $close = null,
    ) {}
}
