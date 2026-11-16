<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Test\Support\InMemoryModelStores;
use Psr\Log\NullLogger;

/**
 * Where a failed model goes: its twin at the other provider first, then the main role, then the strong one.
 */
final class FallbackModelsTest extends AsyncTestCase {
    private InMemoryModelStores $stores;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
        $this->stores->replace('openrouter', [new ModelInfo('openai/gpt-x', 'x', 100_000, 0.000001, 0.000002)]);
        $this->stores->replace('openai', [new ModelInfo('gpt-x', 'x', 100_000, 0.000001, 0.000002)]);
    }

    /** @param list<string> $providers */
    private function fallback(array $providers): FallbackModels {
        $roles = new ModelRoles($this->stores, $this->stores, $providers);
        $roles->seed();
        return new FallbackModels($roles, $this->stores, $this->stores, new NullLogger());
    }

    public function testTwinAtTheOtherProviderComesFirst(): void {
        $both = $this->fallback(['openrouter', 'openai']);

        self::assertSame('openai:gpt-x', $both->next('openai/gpt-x', 1));
        self::assertSame('openrouter:openai/gpt-x', $both->next('openai:gpt-x', 1));
        self::assertSame('fallback', $this->stores->decisions[0]->class);
    }

    public function testWithoutTheOtherKeyTheMainRoleTakesOver(): void {
        $only = $this->fallback(['openrouter']);
        $roles = new ModelRoles($this->stores, $this->stores);

        self::assertSame((string) $roles->model(ModelRole::MAIN), $only->next('openai/gpt-x', null));
        self::assertSame((string) $roles->model(ModelRole::STRONG), $only->next((string) $roles->model(ModelRole::MAIN), null), 'main failed: strong');
    }
}
