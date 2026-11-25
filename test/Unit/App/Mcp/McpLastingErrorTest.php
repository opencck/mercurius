<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use OpenCCK\App\Mcp\McpManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — an error that will not pass by itself is not
 * tried again: no command, a refused login, another version of Node
 * ref: references/hermes-agent/tools/mcp_tool_node_abi.py@e96221fa:L16-L18 (_ABI_MARKERS) — how a native module built
 *      for another Node shows itself
 */
final class McpLastingErrorTest extends TestCase {
    public function testErrorsThatWillNotPassAreKnown(): void {
        self::assertTrue(McpManager::lasting(new RuntimeException('Процесс MCP-сервера завершился (код 127): bash: npx: command not found')));
        self::assertTrue(McpManager::lasting(new RuntimeException('The MCP server process ended (code 127)')));
        self::assertTrue(McpManager::lasting(new RuntimeException('MCP-сервер ответил HTTP 401')));
        self::assertTrue(McpManager::lasting(new RuntimeException('MCP server answered HTTP 403')));
        self::assertTrue(McpManager::lasting(new RuntimeException("was compiled against a different Node.js version using\nNODE_MODULE_VERSION 115")));
        self::assertTrue(McpManager::lasting(new RuntimeException('Error: ERR_DLOPEN_FAILED')));
    }

    public function testErrorsThatMayPassAreTriedAgain(): void {
        self::assertFalse(McpManager::lasting(new RuntimeException('Connection refused')));
        self::assertFalse(McpManager::lasting(new RuntimeException('MCP-сервер ответил HTTP 503')));
        self::assertFalse(McpManager::lasting(new RuntimeException('The MCP server process ended (code 1): listening on port 1270')));
    }
}
