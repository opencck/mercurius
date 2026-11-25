<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;

final class InMemoryMcpServerRepository implements McpServerRepository {
    /** @var array<string, McpServer> context|name => connection */
    public array $servers = [];
    private int $next = 1;

    public function add(string $context, string $name, string $url, ?string $secret): McpServer {
        if (isset($this->servers[$context . '|' . $name])) {
            throw new DomainException(sprintf('MCP-сервер «%s» уже есть.', $name));
        }
        return $this->servers[$context . '|' . $name] = new McpServer($this->next++, $name, $url, $secret, McpServer::PENDING, null, context: $context);
    }

    public function find(string $context, string $name): ?McpServer {
        return $this->servers[$context . '|' . $name] ?? null;
    }

    public function all(): array {
        $all = array_values($this->servers);
        usort($all, static fn(McpServer $a, McpServer $b): int => [$a->context, $a->name] <=> [$b->context, $b->name]);
        return $all;
    }

    public function inContext(string $context): array {
        return array_values(array_filter($this->all(), static fn(McpServer $s): bool => $s->context === $context));
    }

    public function update(string $context, string $name, string $state, ?string $toolsHash, string $reason = '', ?float $nextTry = null): ?McpServer {
        $s = $this->servers[$context . '|' . $name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$context . '|' . $name] = new McpServer($s->id, $s->name, $s->url, $s->secret, $state, $toolsHash ?? $s->toolsHash, $reason, include: $s->include, exclude: $s->exclude, context: $s->context, nextTry: $nextTry, approvedHash: $s->approvedHash, entry: $s->entry);
    }

    public function setFilter(string $context, string $name, ?string $include, string $exclude): ?McpServer {
        $s = $this->servers[$context . '|' . $name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$context . '|' . $name] = new McpServer($s->id, $s->name, $s->url, $s->secret, $s->status, $s->toolsHash, $s->note, include: $include, exclude: $exclude, context: $s->context, nextTry: $s->nextTry, approvedHash: $s->approvedHash, entry: $s->entry);
    }

    public function setAddress(string $context, string $name, string $url, ?string $secret): ?McpServer {
        $s = $this->servers[$context . '|' . $name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$context . '|' . $name] = new McpServer($s->id, $s->name, $url, $secret, $s->status, $s->toolsHash, $s->note, include: $s->include, exclude: $s->exclude, context: $s->context, nextTry: $s->nextTry, approvedHash: $s->approvedHash, entry: $s->entry);
    }

    public function setEntry(string $context, string $name, array $entry): ?McpServer {
        $s = $this->servers[$context . '|' . $name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$context . '|' . $name] = new McpServer($s->id, $s->name, $s->url, $s->secret, $s->status, $s->toolsHash, $s->note, include: $s->include, exclude: $s->exclude, context: $s->context, nextTry: $s->nextTry, approvedHash: $s->approvedHash, entry: $entry);
    }

    public function setApproval(string $context, string $name, ?string $entryHash, ?int $by, ?array $entry = null): ?McpServer {
        $s = $this->servers[$context . '|' . $name] ?? null;
        if ($s === null) {
            return null;
        }
        return $this->servers[$context . '|' . $name] = new McpServer($s->id, $s->name, $s->url, $s->secret, $s->status, $s->toolsHash, $s->note, include: $s->include, exclude: $s->exclude, context: $s->context, nextTry: $s->nextTry, approvedHash: $entryHash, entry: $entry);
    }

    /** @var array<string, array{string, list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>}> */
    private array $tools = [];

    public function saveTools(string $context, string $name, string $listedBy, array $tools): void {
        $this->tools[$context . '|' . $name] = [$listedBy, $tools];
    }

    public function cachedTools(string $context, string $name, string $listedBy): ?array {
        $kept = $this->tools[$context . '|' . $name] ?? null;
        return $kept !== null && $kept[0] === $listedBy ? $kept[1] : null;
    }

    public function remove(string $context, string $name): bool {
        unset($this->tools[$context . '|' . $name]);
        if (!isset($this->servers[$context . '|' . $name])) {
            return false;
        }
        unset($this->servers[$context . '|' . $name]);
        return true;
    }
}
