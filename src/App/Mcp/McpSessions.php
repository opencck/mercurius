<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

/**
 * A client that keeps a connection per server (a stdio process): it is dropped when the server is turned off or
 * removed.
 */
interface McpSessions {
    public function forget(string $server): void;

    /**
     * Whether the server has no process running now: its tools are not listed again only to see them.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
     */
    public function idle(string $server): bool;
}
