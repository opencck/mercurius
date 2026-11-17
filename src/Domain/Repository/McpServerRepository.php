<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;
use OpenCCK\Domain\Mcp\McpServer;

/**
 * spec: BRIEF.md#скиллы-и-mcp
 */
interface McpServerRepository {
    /** @throws DomainException when the name is taken */
    public function add(string $name, string $url, ?string $secret): McpServer;

    public function find(string $name): ?McpServer;

    /** @return list<McpServer> */
    public function all(): array;

    /** A new status; a hash given is stored as the approved set of tools; a note says why. */
    public function update(string $name, string $status, ?string $toolsHash, string $note = ''): ?McpServer;

    public function remove(string $name): bool;

    /** @param string|null $include null — every tool; '' — none */
    public function setFilter(string $name, ?string $include, string $exclude): ?McpServer;
}
