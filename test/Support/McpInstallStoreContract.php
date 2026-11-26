<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Mcp\McpInstall;
use OpenCCK\Domain\Repository\McpInstallRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — an installed MCP: its source, revision,
 * approved tools with their classes and the contexts it is allowed to
 */
abstract class McpInstallStoreContract extends TestCase {
    abstract protected function store(): McpInstallRepository;

    public function testAnInstallGetsItsOwnHolderAndKeepsItsRevisions(): void {
        $store = $this->store();
        $a = $store->add('alpha_mcp', 'https://github.com/x/alpha', 7);
        $b = $store->add('beta_mcp', 'https://github.com/x/beta', 7);

        self::assertNotSame($a->id, $b->id);
        self::assertSame(McpInstall::HOLDER_BASE + $a->id, $a->holder());
        self::assertNull($a->revision);

        $store->setRevision('alpha_mcp', 'r1', 'h1', ['read_it' => 'read'], ['command' => 'node']);
        $store->setRevision('alpha_mcp', 'r2', 'h2', ['read_it' => 'read', 'write_it' => 'irreversible'], ['command' => 'node']);
        $found = $store->find('alpha_mcp') ?? self::fail('kept');

        self::assertSame(['r2', 'r1', 'h2', 7], [$found->revision, $found->previous, $found->toolsHash, $found->installedBy]);
        self::assertSame(['read_it' => 'read', 'write_it' => 'irreversible'], $found->classes);
        self::assertSame(['command' => 'node'], $found->entry);

        $store->setSteps('alpha_mcp', ['npm ci', 'npm run build']);
        self::assertSame(['npm ci', 'npm run build'], $store->find('alpha_mcp')?->steps);
        $store->setLatest('alpha_mcp', 'r3');
        self::assertSame('r3', $store->find('alpha_mcp')?->latest);
        self::assertTrue($store->remove('alpha_mcp'));
        self::assertTrue($store->remove('beta_mcp'));
        self::assertNull($store->find('alpha_mcp'));
    }

    /** spec: brief/skills-mcp.md#установка-и-обновление — the environments the dependencies were built in are kept */
    public function testTheEnvironmentsOfABuildAreKept(): void {
        $store = $this->store();
        $store->add('gamma_mcp', 'https://github.com/x/gamma', 7);

        self::assertNull($store->environment('gamma_mcp'));
        $store->setEnvironment('gamma_mcp', '0a1b2c3d4e5f');
        self::assertSame('0a1b2c3d4e5f', $store->environment('gamma_mcp'));
        $store->remove('gamma_mcp');
    }

    public function testAnInstallIsAllowedToContexts(): void {
        $store = $this->store();
        $store->add('gamma_mcp', 'https://github.com/x/gamma', 7);

        $store->allow('gamma_mcp', 'project:5', true);
        $store->allow('gamma_mcp', 'member:9', true);
        $store->allow('gamma_mcp', 'member:9', false);

        self::assertSame(['project:5'], $store->contexts('gamma_mcp'));
        $store->remove('gamma_mcp');
    }
}
