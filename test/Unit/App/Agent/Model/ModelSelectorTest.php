<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelSelector;
use OpenCCK\App\Agent\Model\TaskProfile;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Test\Support\InMemoryModelStores;
use Psr\Log\NullLogger;

/**
 * Choosing a model for a part of a task: the cheapest one whose catalog indices meet the profile.
 */
final class ModelSelectorTest extends AsyncTestCase {
    private InMemoryModelStores $stores;
    private ModelRoles $roles;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
        $this->roles = new ModelRoles($this->stores, $this->stores);
        $this->roles->seed();
    }

    /**
     * Price per million: input / output; indices: intelligence, coding, agentic.
     *
     * @param array{float, float, float}|null $scores
     * @param list<string> $modalities
     */
    private static function model(string $id, float $in, float $out, ?array $scores, array $modalities = ['text'], int $window = 200_000, bool $tools = true): ModelInfo {
        return new ModelInfo($id, $id, $window, $in / 1e6, $out / 1e6, $modalities, ['text'], $tools ? ['tools'] : [], null, null, null, $scores[0] ?? null, $scores[1] ?? null, $scores[2] ?? null);
    }

    private function catalog(ModelInfo ...$models): void {
        $this->stores->replace('openrouter', array_values($models));
    }

    private function selector(): ModelSelector {
        return new ModelSelector($this->stores, $this->roles, $this->stores, new NullLogger());
    }

    private function standard(): void {
        $this->catalog(
            self::model('a/tiny', 0.05, 0.1, [10.0, 30.0, 20.0]),
            self::model('a/cheap', 0.1, 0.6, [24.0, 71.0, 42.0]),
            self::model('a/mid', 0.4, 1.6, [30.0, 76.0, 40.0], ['text', 'image']),
            self::model('a/agent', 0.15, 0.5, [28.0, 71.0, 51.0]),
            self::model('a/top', 5.0, 25.0, [44.0, 78.0, 57.0], ['text', 'image']),
            self::model('a/unscored', 0.01, 0.02, null),
            self::model('a/notools', 0.01, 0.02, [50.0, 80.0, 60.0], tools: false),
        );
    }

    public function testSimplePartGetsTheCheapestModel(): void {
        $this->standard();

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['text' => 2]), true, 7);

        self::assertSame('openrouter:a/unscored', (string) $model, 'all scores ≤ 3: an unscored cheap model is fine');
        self::assertSame('profile', $this->stores->decisions[0]->method);
    }

    public function testAgencyNeedsAnAgenticModel(): void {
        $this->standard();

        [$model, $reason] = $this->selector()->choose(TaskProfile::fromArray(['agency' => 8]), true, 7);

        self::assertSame('openrouter:a/agent', (string) $model);
        self::assertStringContainsString('агентность', $reason);
    }

    public function testErrorCostRaisesTheBar(): void {
        $this->standard();

        [$plain] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 8]), true, null);
        [$careful] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 8, 'error_cost' => 10]), true, null);

        self::assertSame('openrouter:a/mid', (string) $plain);
        self::assertSame('openrouter:a/top', (string) $careful);
    }

    public function testImagesNeedAVisionModel(): void {
        $this->standard();

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['images' => 7]), true, null);

        self::assertSame('openrouter:a/mid', (string) $model);
    }

    public function testLargeContextNeedsALargeWindow(): void {
        $this->catalog(self::model('a/small', 0.1, 0.2, [30.0, 70.0, 40.0], window: 32_000), self::model('a/large', 0.3, 0.9, [30.0, 70.0, 40.0], window: 1_000_000));

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['context' => 7]), true, null);

        self::assertSame('openrouter:a/large', (string) $model);
    }

    public function testEmptyCatalogFallsBackToTheStrongRole(): void {
        [$model] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 9]), true, null);

        self::assertSame((string) $this->roles->model(ModelRole::STRONG), (string) $model);
        self::assertSame('fallback', $this->stores->decisions[0]->method);
        self::assertSame('strong', $this->stores->decisions[0]->role);
    }

    public function testCatalogWithoutIndicesFallsBackForDemandingWork(): void {
        $this->catalog(self::model('a/one', 0.1, 0.2, null), self::model('a/two', 0.2, 0.4, null));

        [$model, $reason] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 7]), true, null);
        [$simple] = $this->selector()->choose(TaskProfile::fromArray(['text' => 2]), true, null);

        self::assertSame((string) $this->roles->model(ModelRole::STRONG), (string) $model);
        self::assertStringContainsString('нет оценок', $reason);
        self::assertSame('openrouter:a/one', (string) $simple, 'simple work may go to an unscored model');
    }

    public function testCostOfErrorAloneRaisesEveryAxis(): void {
        $this->standard();

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['error_cost' => 10]), true, null);

        self::assertNotSame('openrouter:a/unscored', (string) $model);
        self::assertNotSame('openrouter:a/tiny', (string) $model);
    }

    public function testNothingPricierThanTheStrongRole(): void {
        $strong = $this->roles->model(ModelRole::STRONG)->model;
        $this->catalog(
            self::model('a/cheap', 0.1, 0.6, [24.0, 71.0, 42.0]),
            self::model($strong, 5.0, 25.0, [44.0, 78.0, 57.0]),
            self::model('a/pro', 15.0, 120.0, [50.0, 80.0, 60.0]),
        );

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 10]), true, null);

        self::assertSame('openrouter:' . $strong, (string) $model);
    }

    public function testVariantEntriesAreSkipped(): void {
        // :batch is served only by the Batch API, :free has its own limits: neither fits an interactive run.
        $this->catalog(self::model('a/top:batch', 0.05, 0.1, [50.0, 80.0, 60.0]), self::model('a/top', 0.1, 0.2, [50.0, 80.0, 60.0]));

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 8]), true, null);

        self::assertSame('openrouter:a/top', (string) $model);
    }

    public function testAPartWithToolsSkipsAModelWhoseToolCallsOftenFailInTheCatalog(): void {
        // spec: BRIEF.md#выбор-моделей-под-задачу — tool calls that do not pass their schemas, by the catalog
        $this->standard();
        $this->stores->markToolReliable('openrouter', ['a/tiny', 'a/cheap', 'a/mid', 'a/agent', 'a/top', 'a/notools']);

        [$withTools] = $this->selector()->choose(TaskProfile::fromArray(['text' => 2]), true, 7);
        [$withoutTools] = $this->selector()->choose(TaskProfile::fromArray(['text' => 2]), false, 7);

        self::assertSame('openrouter:a/tiny', (string) $withTools, 'a/unscored failed the share of good tool calls');
        self::assertContains((string) $withoutTools, ['openrouter:a/unscored', 'openrouter:a/notools'], 'a part without tools does not care');
    }

    public function testModelsThatOftenFailedHereAreSkipped(): void {
        $this->standard();
        $reliability = new class implements \OpenCCK\Domain\Repository\ModelReliabilityRepository {
            public function failures(\DateTimeImmutable $since): array {
                return [
                    'openrouter:a/agent' => ['runs' => 4, 'failures' => 3, 'last' => time() - 60],
                    // Two runs are not enough to judge.
                    'openrouter:a/cheap' => ['runs' => 2, 'failures' => 2, 'last' => time() - 60],
                    // Excluded long enough ago: it gets a trial run again.
                    'openrouter:a/mid' => ['runs' => 5, 'failures' => 5, 'last' => time() - 4 * 86400],
                ];
            }

            public function latency(\DateTimeImmutable $since): array {
                return [];
            }
        };
        $selector = new ModelSelector($this->stores, $this->roles, $this->stores, new NullLogger(), $reliability);

        [$model, $reason] = $selector->choose(TaskProfile::fromArray(['agency' => 8]), true, null);

        // The bar stays where a/agent set it; the next model over it is a/top.
        self::assertSame('openrouter:a/top', (string) $model);
        self::assertStringContainsString('без 1 часто отказывавших', $reason);
    }

    public function testEveryProviderWithAKeyIsSearched(): void {
        $this->standard();
        $this->stores->replace('openai', [self::model('gpt-cheaper', 0.01, 0.05, [30.0, 76.0, 52.0])]);
        $roles = new ModelRoles($this->stores, $this->stores, ['openrouter', 'openai']);
        $selector = new ModelSelector($this->stores, $roles, $this->stores, new NullLogger());

        [$model] = $selector->choose(TaskProfile::fromArray(['agency' => 8]), true, null);
        [$without] = $this->selector()->choose(TaskProfile::fromArray(['agency' => 8]), true, null);

        self::assertSame('openai:gpt-cheaper', (string) $model);
        self::assertStringStartsWith('openrouter:', (string) $without, 'no key, no OpenAI models');
    }

    public function testPriceTieGoesToTheDirectProvider(): void {
        $this->catalog(self::model('openai/gpt-x', 0.1, 0.2, [40.0, 70.0, 50.0]));
        $this->stores->replace('openai', [self::model('gpt-x', 0.1, 0.2, [40.0, 70.0, 50.0])]);
        $roles = new ModelRoles($this->stores, $this->stores, ['openrouter', 'openai']);

        [$model] = (new ModelSelector($this->stores, $roles, $this->stores, new NullLogger()))->choose(TaskProfile::fromArray(['text' => 5]), true, null);

        self::assertSame('openai:gpt-x', (string) $model);
    }

    public function testUrgentPartSkipsModelsSlowHere(): void {
        $this->standard();
        $reliability = new class implements \OpenCCK\Domain\Repository\ModelReliabilityRepository {
            public function failures(\DateTimeImmutable $since): array {
                return [];
            }

            public function latency(\DateTimeImmutable $since): array {
                return ['openrouter:a/unscored' => ['calls' => 9, 'p50' => 40_000, 'p95' => 300_000]];
            }
        };
        $selector = new ModelSelector($this->stores, $this->roles, $this->stores, new NullLogger(), $reliability);

        [$calm] = $selector->choose(TaskProfile::fromArray(['text' => 2]), true, null);
        [$urgent, $reason] = $selector->choose(TaskProfile::fromArray(['text' => 2, 'urgency' => 8]), true, null);

        self::assertSame('openrouter:a/unscored', (string) $calm, 'not urgent: the cheapest');
        self::assertNotSame('openrouter:a/unscored', (string) $urgent);
        self::assertStringContainsString('медленных', $reason);
    }

    public function testZeroPricedModelIsSkipped(): void {
        $this->catalog(self::model('a/promo', 0.0, 0.0, [50.0, 80.0, 60.0]), self::model('a/paid', 0.1, 0.2, [30.0, 70.0, 40.0]));

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['text' => 2]), true, null);

        self::assertSame('openrouter:a/paid', (string) $model);
    }

    public function testEscalationRaisesTheRequirements(): void {
        $this->standard();

        [$model] = $this->selector()->choose(TaskProfile::fromArray(['reasoning' => 8]), true, null, raise: 2);

        self::assertSame('openrouter:a/top', (string) $model);
    }

    public function testProfileIsClampedAndUnknownAxesIgnored(): void {
        $profile = TaskProfile::fromArray(['code' => 42, 'reasoning' => -3, 'magic' => 9, 'text' => 'много']);

        self::assertSame(10, $profile->score('code'));
        self::assertSame(1, $profile->score('reasoning'));
        self::assertSame(1, $profile->score('text'));
    }

    public function testScoresAcceptNumbersInAnyForm(): void {
        $profile = TaskProfile::fromArray(['code' => '7', 'reasoning' => 6.6, 'agency' => 5.0]);

        self::assertSame(7, $profile->score('code'));
        self::assertSame(7, $profile->score('reasoning'));
        self::assertSame(5, $profile->score('agency'));
    }

    public function testOnlyCapabilitiesMakeAProfileDemanding(): void {
        self::assertFalse(TaskProfile::fromArray([])->demanding());
        self::assertFalse(TaskProfile::fromArray(['error_cost' => 10, 'urgency' => 9])->demanding());
        self::assertTrue(TaskProfile::fromArray(['images' => 6])->demanding());
    }
}
