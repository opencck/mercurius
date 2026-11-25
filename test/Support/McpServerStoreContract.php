<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — a connection lives in its context
 */
abstract class McpServerStoreContract extends TestCase {
    abstract protected function store(): McpServerRepository;

    public function testANameIsUniqueWithinItsContextOnly(): void {
        $store = $this->store();
        $store->add('project:1', 'docs', 'https://a.example/mcp', null);
        $store->add('project:2', 'docs', 'https://b.example/mcp', 'token');

        self::assertSame('https://a.example/mcp', $store->find('project:1', 'docs')?->url);
        self::assertSame(['project:2', 'token'], [$store->find('project:2', 'docs')?->context, $store->find('project:2', 'docs')?->secret]);
        self::assertSame(['docs'], array_map(static fn(McpServer $s): string => $s->name, $store->inContext('project:1')));
        self::assertNull($store->find('member:1', 'docs'));
        $this->expectException(DomainException::class);
        $store->add('project:1', 'docs', 'https://c.example/mcp', null);
    }

    public function testTheStateIsKeptWithItsReasonAndNextTry(): void {
        $store = $this->store();
        $added = $store->add('member:7', 'docs', 'https://a.example/mcp', null);
        self::assertSame(McpServer::PENDING, $added->status);

        $store->update('member:7', 'docs', McpServer::ERROR, 'h1', 'Connection refused', 1_000_000.0);

        $found = $store->find('member:7', 'docs');
        self::assertSame([McpServer::ERROR, 'h1', 'Connection refused', 1_000_000.0], [$found?->status, $found?->toolsHash, $found?->note, $found?->nextTry]);
        self::assertTrue($found?->enabled());
        $store->update('member:7', 'docs', McpServer::CONNECTED, null);
        self::assertSame(['h1', null], [$store->find('member:7', 'docs')?->toolsHash, $store->find('member:7', 'docs')?->nextTry], 'the hash stays, the next try goes');
        self::assertTrue($store->remove('member:7', 'docs'));
        self::assertFalse($store->remove('member:7', 'docs'));
    }

    /** spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — the kept tools go with what listed them */
    public function testTheToolsAreKeptWithWhatListedThem(): void {
        $store = $this->store();
        $store->add('member:8', 'local', 'stdio:npx -y some-mcp', null);
        $tools = [['name' => 'echo', 'description' => 'Echoes «всё»', 'schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]]];

        self::assertNull($store->cachedTools('member:8', 'local', 'e1'), 'nothing kept yet');
        $store->saveTools('member:8', 'local', 'e1', $tools);

        self::assertSame($tools, $store->cachedTools('member:8', 'local', 'e1'));
        self::assertNull($store->cachedTools('member:8', 'local', 'e2'), 'listed by another entry');
        $store->remove('member:8', 'local');
    }
}
