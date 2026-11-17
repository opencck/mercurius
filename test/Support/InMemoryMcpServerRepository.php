<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;

final class InMemoryMcpServerRepository implements McpServerRepository {
    /** @var array<string, McpServer> */
    public array $servers = [];

    public function add(string $name, string $url, ?string $secret): McpServer {
        if (isset($this->servers[$name])) {
            throw new DomainException(sprintf('MCP-сервер «%s» уже есть.', $name));
        }
        return $this->servers[$name] = new McpServer(count($this->servers) + 1, $name, $url, $secret, McpServer::PENDING, null);
    }

    public function find(string $name): ?McpServer {
        return $this->servers[$name] ?? null;
    }

    public function all(): array {
        return array_values($this->servers);
    }

    public function update(string $name, string $status, ?string $toolsHash, string $note = ''): ?McpServer {
        $s = $this->servers[$name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$name] = new McpServer($s->id, $s->name, $s->url, $s->secret, $status, $toolsHash ?? $s->toolsHash, $note, include: $s->include, exclude: $s->exclude);
    }

    public function setFilter(string $name, ?string $include, string $exclude): ?McpServer {
        $s = $this->servers[$name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$name] = new McpServer($s->id, $s->name, $s->url, $s->secret, $s->status, $s->toolsHash, $s->note, include: $include, exclude: $exclude);
    }

    public function remove(string $name): bool {
        if (!isset($this->servers[$name])) {
            return false;
        }
        unset($this->servers[$name]);
        return true;
    }
}
