<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;
use OpenCCK\Domain\Mcp\McpServer;

/**
 * The connections to MCP servers, each in its context: a name is unique within its context.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение
 */
interface McpServerRepository {
    /** @throws DomainException when the name is taken in the context */
    public function add(string $context, string $name, string $url, ?string $secret): McpServer;

    public function find(string $context, string $name): ?McpServer;

    /** @return list<McpServer> every connection, by context and name */
    public function all(): array;

    /** @return list<McpServer> the connections of one context, by name */
    public function inContext(string $context): array;

    /**
     * A new state with why; a hash given is stored as the approved set of tools; $nextTry — when a connection in
     * error is tried again (unix time).
     */
    public function update(string $context, string $name, string $state, ?string $toolsHash, string $reason = '', ?float $nextTry = null): ?McpServer;

    public function remove(string $context, string $name): bool;

    /** Where the connection leads and its secret, as its .mcp.json entry says now. */
    public function setAddress(string $context, string $name, string $url, ?string $secret): ?McpServer;

    /**
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the approved entry is kept by its hash and who approved it
     */
    public function setApproval(string $context, string $name, ?string $entryHash, ?int $by): ?McpServer;

    /** @param string|null $include null — every tool; '' — none */
    public function setFilter(string $context, string $name, ?string $include, string $exclude): ?McpServer;
}
