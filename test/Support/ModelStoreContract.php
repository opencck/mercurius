<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\ModelRoleRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;

/**
 * Behaviour of the model catalog and the role table, run on in-memory fakes and on PostgreSQL.
 */
abstract class ModelStoreContract extends AsyncTestCase {
    protected ModelCatalogRepository $catalog;
    protected ModelRoleRepository $roles;
    protected RoutingDecisionRepository $decisions;
    /** who set a role, as the store keeps it: a stored participant where the store checks it */
    protected int $owner = 1;

    private static function model(string $id, ?int $window = 131072): ModelInfo {
        return new ModelInfo($id, $id, $window, 0.0000001, 0.0000005, ['text', 'image'], ['text'], ['tools', 'reasoning'], 4096);
    }

    public function testCatalogIsReplacedPerProvider(): void {
        $this->catalog->replace('openrouter', [self::model('a/one'), self::model('a/two', null)]);
        $this->catalog->replace('anthropic', [self::model('claude-x')]);
        $this->catalog->replace('openrouter', [self::model('a/two', 2048)]);

        self::assertNull($this->catalog->find(ModelRef::parse('a/one')));
        self::assertNotNull($this->catalog->find(ModelRef::parse('anthropic:claude-x')));
        self::assertNull($this->catalog->find(ModelRef::parse('anthropic:a/two')));
        $two = $this->catalog->find(ModelRef::parse('openrouter:a/two'));
        self::assertNotNull($two);
        self::assertSame(2048, $two->contextLength);
        self::assertTrue($two->supportsTools());
        self::assertSame(['text', 'image'], $two->inputModalities);
        self::assertNotNull($this->catalog->fetchedAt('openrouter'));
        self::assertNull($this->catalog->fetchedAt('openai'));
    }

    public function testScoresAreKeptAndAllModelsListed(): void {
        $this->catalog->replace('openrouter', [
            new ModelInfo('a/scored', 'a', 1000, 0.000001, 0.000002, ['text'], ['text'], ['tools'], null, null, null, 44.2, 78.0, 56.5),
            new ModelInfo('a/plain', 'b', 1000, null, null),
        ]);

        $scored = $this->catalog->find(ModelRef::parse('a/scored'));
        self::assertNotNull($scored);
        self::assertSame(44.2, $scored->intelligenceIndex);
        self::assertSame(78.0, $scored->codingIndex);
        self::assertSame(56.5, $scored->agenticIndex);
        self::assertNull($this->catalog->find(ModelRef::parse('a/plain'))?->agenticIndex);
        self::assertSame(['a/plain', 'a/scored'], array_map(static fn(ModelInfo $m): string => $m->id, $this->catalog->models('openrouter')));
    }

    public function testSeedAddsOnlyMissingRoles(): void {
        $this->roles->set(ModelRole::MAIN, ModelRef::parse('x/main'), 'выбрал владелец', ModelRole::SET_BY_OWNER);

        $this->roles->seed([ModelRole::MAIN => ModelRef::parse('seed/main'), ModelRole::CHEAP => ModelRef::parse('anthropic:seed-cheap')], 'начальная таблица');

        $all = $this->roles->all();
        self::assertSame('openrouter:x/main', (string) $all[ModelRole::MAIN]->model);
        self::assertSame('anthropic:seed-cheap', (string) $all[ModelRole::CHEAP]->model);
        self::assertSame(ModelRole::SET_BY_SEED, $all[ModelRole::CHEAP]->setBy);
    }

    public function testOnlyTheOwnerChangesWhatTheOwnerFixed(): void {
        $this->roles->set(ModelRole::STRONG, ModelRef::parse('x/strong'), 'выбрал владелец', ModelRole::SET_BY_OWNER);

        self::assertFalse($this->roles->set(ModelRole::STRONG, ModelRef::parse('y/strong'), 'агенту кажется лучше', ModelRole::SET_BY_AGENT));
        self::assertTrue($this->roles->set(ModelRole::CHEAP, ModelRef::parse('y/cheap'), 'дешевле при том же качестве', ModelRole::SET_BY_AGENT));
        self::assertTrue($this->roles->set(ModelRole::STRONG, ModelRef::parse('z/strong'), 'владелец передумал', ModelRole::SET_BY_OWNER));

        $all = $this->roles->all();
        self::assertSame('openrouter:z/strong', (string) $all[ModelRole::STRONG]->model);
        self::assertSame('openrouter:y/cheap', (string) $all[ModelRole::CHEAP]->model);
        self::assertSame('дешевле при том же качестве', $all[ModelRole::CHEAP]->reason);
    }

    public function testEveryAcceptedChangeGoesIntoTheHistory(): void {
        $this->roles->set(ModelRole::STRONG, ModelRef::parse('x/strong'), 'выбрал владелец', ModelRole::SET_BY_OWNER, $this->owner);
        $this->roles->set(ModelRole::STRONG, ModelRef::parse('y/strong'), 'агенту кажется лучше', ModelRole::SET_BY_AGENT);

        $history = $this->roles->history(5);
        self::assertSame('openrouter:x/strong', (string) ($history[0]['model'] ?? ''), 'a refused change is not history');
        self::assertSame('выбрал владелец', $history[0]['reason'] ?? null);
        self::assertSame($this->owner, $history[0]['by'] ?? null);
        self::assertSame(ModelRole::STRONG, $history[0]['role']);
    }
}
