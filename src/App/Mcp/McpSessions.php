<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

/**
 * A client that keeps a connection per server (a stdio process): it is dropped when the server is turned off or
 * removed.
 */
interface McpSessions {
    public function forget(string $server): void;
}
