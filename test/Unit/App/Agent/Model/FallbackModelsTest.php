<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Model\ModelLists;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ProviderPauses;
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

    public function testAModelOffTheChoiceWithNoOtherInItsCategoryGoesToTheFallbackCategory(): void {
        // spec: brief/models.md#запасные-модели — the owner named the fallback models: a subscription off the choice
        //       moves there, while without them it does not move to a model of another category
        $this->stores->replace('antigravity', [new ModelInfo('claude-sonnet-4-6', 's', 120_000, 0.0, 0.0, ['text'], ['text'], ['tools'])]);
        $this->stores->replace('openrouter', [new ModelInfo('nvidia/nemo:free', 'n', 100_000, 0.0, 0.0, ['text'], ['text'], ['tools']), new ModelInfo('author/main', 'm', 100_000, 0.000001, 0.000002, ['text'], ['text'], ['tools'])]);
        $pauses = new ProviderPauses(null, static fn(): int => 1000);
        $pauses->pause('antigravity', 5000, ProviderPauses::ACCOUNT);
        $with = ModelLists::fromEnv(['LLM_MAIN_MODELS' => 'antigravity:claude-sonnet-4-6', 'LLM_STRONG_MODELS' => 'author/main', 'LLM_FALLBACK_MODELS' => 'nvidia/nemo:free'], 'author/main');
        $without = ModelLists::fromEnv(['LLM_MAIN_MODELS' => 'antigravity:claude-sonnet-4-6', 'LLM_STRONG_MODELS' => 'author/main'], 'author/main');

        $fallback = new FallbackModels(new ModelRoles($this->stores, $this->stores, ['openrouter', 'antigravity'], null, $with), $this->stores, $this->stores, new NullLogger(), $pauses);
        $none = new FallbackModels(new ModelRoles($this->stores, $this->stores, ['openrouter', 'antigravity'], null, $without), $this->stores, $this->stores, new NullLogger(), $pauses);

        self::assertSame('openrouter:nvidia/nemo:free', $fallback->next('antigravity:claude-sonnet-4-6', 1));
        self::assertNull($none->next('antigravity:claude-sonnet-4-6', 1));
        self::assertNotSame('openrouter:nvidia/nemo:free', $fallback->next('openrouter:nvidia/nemo:free', 1), 'a fallback model is not its own fallback');
    }

    public function testWithoutTheOtherKeyTheMainRoleTakesOver(): void {
        $only = $this->fallback(['openrouter']);
        $roles = new ModelRoles($this->stores, $this->stores);

        self::assertSame((string) $roles->model(ModelRole::MAIN), $only->next('openai/gpt-x', null));
        self::assertSame((string) $roles->model(ModelRole::STRONG), $only->next((string) $roles->model(ModelRole::MAIN), null), 'main failed: strong');
    }
}
