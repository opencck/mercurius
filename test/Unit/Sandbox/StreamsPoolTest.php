<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use OpenCCK\Sandbox\Streams;
use OpenCCK\Sandbox\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — MCP processes have a count of their own:
 * they do not take the members' places, nor the members theirs
 */
final class StreamsPoolTest extends TestCase {
    public function testMcpProcessesCountApartFromMembers(): void {
        self::assertSame(Streams::POOL_MEMBERS, Streams::pool(7));
        self::assertSame(Streams::POOL_MEMBERS, Streams::pool(Workspace::OWNERS));
        self::assertSame(Streams::POOL_MCP, Streams::pool(Streams::MCP_BASE));
        self::assertSame(Streams::POOL_MCP, Streams::pool(Streams::MCP_BASE + 42));
    }
}
