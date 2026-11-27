<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use DomainException;
use Mercurius\Mcp\Models\McpModel;
use OpenCCK\App\Agent\Model\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Providers that come and go: a name is held by one owner, the names of the installation are never taken, and a
 * provider that went off stays known with a reason.
 *
 * spec: brief/models.md#реестр-провайдеров
 */
final class ProviderRegistryTest extends TestCase {
    private static function model(string $id, string $kind = 'image'): McpModel {
        return new McpModel($id, $kind, $kind, null, ['text'], false, '', null);
    }

    public function testANameIsHeldByOneOwnerAndTheNamesOfTheInstallationByNone(): void {
        $registry = new ProviderRegistry(['openrouter', 'gemini']);

        $registry->add('mcp-gemini-api', ProviderRegistry::MCP, 'owners|gemini-api', 'MCP gemini-api', [self::model('img')]);
        $registry->add('mcp-gemini-api', ProviderRegistry::MCP, 'owners|gemini-api', 'MCP gemini-api', [self::model('img'), self::model('img2')]);

        self::assertNotNull($registry->takenBy('mcp-gemini-api', 'owners|other'));
        self::assertNull($registry->takenBy('mcp-gemini-api', 'owners|gemini-api'), 'the owner puts it in again');
        self::assertNotNull($registry->takenBy('gemini', 'owners|x'), 'a name of the installation is reserved, key or not');
        self::assertNotNull($registry->model('mcp-gemini-api', 'img2'));
        $this->expectException(DomainException::class);
        $registry->add('mcp-gemini-api', ProviderRegistry::MCP, 'owners|other', 'x', []);
    }

    public function testAProviderThatWentOffIsKnownWithItsReasonAndNotCalled(): void {
        $registry = new ProviderRegistry();
        $registry->add('mcp-a', ProviderRegistry::MCP, 'owners|a', 'MCP a', [self::model('m')]);

        $registry->suspend('owners|a', 'выключен владельцем');

        self::assertSame([], $registry->available());
        self::assertFalse($registry->isAvailable('mcp-a'));
        self::assertSame('выключен владельцем', $registry->reason('mcp-a'));
        self::assertNull($registry->client('mcp-a'));
        self::assertArrayHasKey('mcp-a', $registry->all(), 'still known');

        $registry->add('mcp-a', ProviderRegistry::MCP, 'owners|a', 'MCP a', [self::model('m')]);
        self::assertSame(['mcp-a'], $registry->available(), 'back when it is on again');

        $registry->remove('owners|a');
        self::assertSame([], $registry->all());
    }
}
