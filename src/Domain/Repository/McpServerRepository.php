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
    /**
     * Keeps the approved entry of a connection approved before entries were kept; the approval itself stays.
     *
     * @param array<array-key, mixed> $entry
     */
    public function setEntry(string $context, string $name, array $entry): ?McpServer;

    /** @param array<array-key, mixed>|null $entry the approved entry itself, kept to call the server by it */
    public function setApproval(string $context, string $name, ?string $entryHash, ?int $by, ?array $entry = null): ?McpServer;

    /**
     * The hash of the entry its card was sent for: a restart does not send it again.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
     */
    public function asked(string $context, string $name): ?string;

    public function setAsked(string $context, string $name, string $entryHash): void;

    /** @param string|null $include null — every tool; '' — none */
    public function setFilter(string $context, string $name, ?string $include, string $exclude): ?McpServer;

    /**
     * The tools a server listed, kept with what they were listed by: an entry or a command.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
     *
     * @param list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> $tools
     */
    public function saveTools(string $context, string $name, string $listedBy, array $tools): void;

    /**
     * The kept tools when they were listed by this entry; null — none, or listed by another.
     *
     * @return list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>|null
     */
    public function cachedTools(string $context, string $name, string $listedBy): ?array;
}
