<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Module;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Infrastructure\Module\ModelCatalogModule;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\RecordingLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

use function Amp\delay;

final class ModelCatalogModuleTest extends AsyncTestCase {
    private InMemoryModelStores $stores;
    private int $fetches = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
    }

    /** @return list<ModelInfo> */
    private function fetch(): array {
        $this->fetches++;
        return [new ModelInfo('a/model-' . $this->fetches, 'model', 1000, null, null)];
    }

    public function testStartLoadsAnEmptyCatalog(): void {
        $module = new ModelCatalogModule(['openrouter' => $this->fetch(...)], $this->stores, new NullLogger());

        $module->start();
        $module->idle();
        $module->stop();

        self::assertNotNull($this->stores->find(ModelRef::parse('a/model-1')));
    }

    public function testFreshCatalogIsNotReloadedAtStart(): void {
        $this->stores->replace('openrouter', [new ModelInfo('a/old', 'old', 1000, null, null)]);
        $module = new ModelCatalogModule(['openrouter' => $this->fetch(...)], $this->stores, new NullLogger());

        $module->start();
        $module->idle();
        $module->stop();

        self::assertSame(0, $this->fetches);
    }

    public function testFailureKeepsTheOldCatalog(): void {
        $this->stores->replace('openrouter', [new ModelInfo('a/old', 'old', 1000, null, null)]);
        $this->stores->fetched['openrouter'] = new \DateTimeImmutable('-2 days');
        $failing = static function (): array {
            throw new LlmException(ErrorKind::Network, 'down');
        };
        $module = new ModelCatalogModule(['openrouter' => $failing], $this->stores, new NullLogger());

        $module->start();
        $module->idle();
        $module->stop();

        self::assertNotNull($this->stores->find(ModelRef::parse('a/old')));
    }

    public function testEmptyAnswerKeepsTheOldCatalog(): void {
        $this->stores->replace('openrouter', [new ModelInfo('a/old', 'old', 1000, null, null)]);
        $this->stores->fetched['openrouter'] = new \DateTimeImmutable('-2 days');
        $module = new ModelCatalogModule(['openrouter' => static fn(): array => []], $this->stores, new NullLogger());

        $module->start();
        $module->idle();
        $module->stop();

        self::assertNotNull($this->stores->find(ModelRef::parse('a/old')));
    }

    public function testAProviderNotConnectedIsSkippedQuietly(): void {
        $logger = new RecordingLogger();
        $module = new ModelCatalogModule(['chatgpt' => $this->fetch(...)], $this->stores, $logger, connected: ['chatgpt' => static fn(): bool => false]);

        $module->start();
        $module->idle();
        $module->stop();

        self::assertSame(0, $this->fetches);
        self::assertSame([], array_filter($logger->records, static fn(array $r): bool => $r['level'] === LogLevel::WARNING));
    }

    public function testReloadLoadsTheCatalogOnceTheProviderIsConnected(): void {
        $connected = false;
        $module = new ModelCatalogModule(['chatgpt' => $this->fetch(...)], $this->stores, new NullLogger(), connected: ['chatgpt' => static function () use (&$connected): bool {
            return $connected;
        }]);
        $module->start();
        $module->idle();

        $connected = true;
        $module->reload('chatgpt');
        $module->idle();
        $module->stop();

        self::assertSame(1, $this->fetches);
        self::assertNotNull($this->stores->find(ModelRef::parse('chatgpt:a/model-1')));
    }

    public function testReloadOfAnUnknownProviderDoesNothing(): void {
        $module = new ModelCatalogModule(['openrouter' => $this->fetch(...)], $this->stores, new NullLogger());
        $this->stores->replace('openrouter', [new ModelInfo('a/old', 'old', 1000, null, null)]);
        $module->start();

        $module->reload('chatgpt');
        $module->idle();
        $module->stop();

        self::assertSame(0, $this->fetches);
    }

    public function testTimerReloadsEveryInterval(): void {
        $module = new ModelCatalogModule(['openrouter' => $this->fetch(...)], $this->stores, new NullLogger(), 0.05);

        $module->start();
        delay(0.18);
        $module->idle();
        $module->stop();

        self::assertGreaterThanOrEqual(3, $this->fetches);
    }
}
