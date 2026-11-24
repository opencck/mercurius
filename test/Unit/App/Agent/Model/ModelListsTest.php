<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use DomainException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelLists;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryModelStores;
use PHPUnit\Framework\TestCase;

/**
 * The agent calls only the models the owner listed: a new installation has the starting model in the three text lists
 * and nothing elsewhere; within a list the agent chooses itself.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 */
final class ModelListsTest extends TestCase {
    public function testANewInstallationHasOnlyTheStartingModel(): void {
        $lists = ModelLists::fromEnv([], 'author/start');

        foreach ([ModelRole::CHEAP, ModelRole::MAIN, ModelRole::STRONG] as $role) {
            self::assertSame(['openrouter:author/start'], array_map('strval', $lists->of($role)), $role);
        }
        foreach (array_keys(ModelLists::OTHER) as $category) {
            self::assertSame([], $lists->other($category), $category . ' is empty until the owner names a model');
        }
        self::assertTrue($lists->allowsText(ModelRef::parse('author/start')));
        self::assertFalse($lists->allowsText(ModelRef::parse('openai/gpt-5.6-luna')), 'no model is built in');
    }

    public function testTheOwnerFillsTheListsAndTheOtherCategories(): void {
        $lists = ModelLists::fromEnv([
            'LLM_MAIN_MODELS' => 'author/a, openai:b ,author/a',
            'LLM_STRONG_MODELS' => 'anthropic:big',
            'WEB_SEARCH_MODELS' => 'author/search',
            'MEDIA_VISION_MODELS' => 'openrouter:author/eyes, author/eyes2',
        ], 'author/start');

        self::assertSame(['openrouter:author/a', 'openai:b'], array_map('strval', $lists->of(ModelRole::MAIN)), 'in the owner\'s order, once each');
        self::assertSame(['openrouter:author/start'], array_map('strval', $lists->of(ModelRole::CHEAP)), 'an unfilled list is the starting model');
        self::assertTrue($lists->allows(ModelRole::STRONG, ModelRef::parse('anthropic:big')));
        self::assertFalse($lists->allows(ModelRole::MAIN, ModelRef::parse('anthropic:big')), 'a list is of its own role');
        self::assertTrue($lists->allowsText(ModelRef::parse('anthropic:big')));
        self::assertSame(['author/search'], $lists->other('search'));
        self::assertSame(['openrouter:author/eyes', 'author/eyes2'], $lists->other('vision'));
    }

    public function testTheAgentChoosesTheModelOfARoleOnlyFromItsList(): void {
        $catalog = new InMemoryModelStores();
        $catalog->replace('openrouter', array_map(self::info(...), ['author/a', 'author/b', 'author/outside']));
        $lists = ModelLists::fromEnv(['LLM_MAIN_MODELS' => 'author/a,author/b'], 'author/a');
        $roles = new ModelRoles($catalog, $catalog, ['openrouter'], null, $lists);
        $roles->seed();
        $agent = new Principal(2, PrincipalKind::Agent, Role::admin(), 'agent');

        self::assertSame('openrouter:author/a', (string) $roles->model(ModelRole::MAIN), 'the first of the list until the agent chooses');
        $roles->set(ModelRole::MAIN, 'author/b', 'быстрее на этой задаче', $agent, false);
        self::assertSame('openrouter:author/b', (string) $roles->model(ModelRole::MAIN));

        try {
            $roles->set(ModelRole::MAIN, 'author/outside', 'хочу другую модель', $agent, false);
            self::fail('a model outside the list was accepted');
        } catch (DomainException $e) {
            self::assertStringContainsString('LLM_MAIN_MODELS', $e->getMessage(), 'the refusal names the setting the owner fills');
        }
        self::assertTrue($roles->allowedText(ModelRef::parse('author/b')));
        self::assertFalse($roles->allowedText(ModelRef::parse('author/outside')));

        // the owner took the chosen model out of the list: the role falls back to the first of the list
        $narrowed = new ModelRoles($catalog, $catalog, ['openrouter'], null, ModelLists::fromEnv(['LLM_MAIN_MODELS' => 'author/a'], 'author/a'));
        self::assertSame('openrouter:author/a', (string) $narrowed->model(ModelRole::MAIN));
        self::assertSame('openrouter:author/a', (string) $narrowed->model('media'), 'there is no media role any more');
    }

    public function testTheReportOfRolesNamesTheModelThatWorksNotTheOneLeftInTheTable(): void {
        $catalog = new InMemoryModelStores();
        $catalog->replace('openrouter', array_map(self::info(...), ['author/old', 'author/a', 'author/b']));
        // the table still holds the model the role had before the owner rewrote its list
        (new ModelRoles($catalog, $catalog, ['openrouter'], null, ModelLists::fromEnv(['LLM_CHEAP_MODELS' => 'author/old'], 'author/a')))->seed();
        $roles = new ModelRoles($catalog, $catalog, ['openrouter'], null, ModelLists::fromEnv(['LLM_CHEAP_MODELS' => 'author/a,author/b'], 'author/a'));

        self::assertSame('openrouter:author/old', (string) $roles->all()[ModelRole::CHEAP]->model, 'the record stays for the history');
        $current = $roles->current();
        self::assertSame('openrouter:author/a', (string) $current[ModelRole::CHEAP]->model, 'the first of the list works');
        self::assertSame(ModelRole::SET_BY_LIST, $current[ModelRole::CHEAP]->setBy);
        self::assertStringContainsString('author/old', $current[ModelRole::CHEAP]->reason, 'and the report says which choice is gone');
        self::assertSame('openrouter:author/a', (string) $current[ModelRole::MAIN]->model);
        self::assertSame(ModelRole::SET_BY_SEED, $current[ModelRole::MAIN]->setBy, 'a choice still in its list is shown as it is');
    }

    public function testAModelOutsideTheCatalogIsRefusedWithTheNamesThatAreLikeIt(): void {
        $catalog = new InMemoryModelStores();
        $catalog->replace('claude', array_map(self::info(...), ['claude-opus-5-5', 'claude-fable-5-2', 'claude-fable-5-1', 'claude-haiku-5-5']));
        $roles = new ModelRoles($catalog, $catalog, ['claude'], null, null);

        try {
            $roles->check(ModelRole::STRONG, ModelRef::parse('claude:fable-5.2'));
            self::fail('a model the catalog does not know was accepted');
        } catch (DomainException $e) {
            self::assertStringContainsString('claude-fable-5-2', $e->getMessage());
            self::assertStringContainsString('claude-fable-5-1', $e->getMessage());
            self::assertStringNotContainsString('claude-haiku', $e->getMessage(), 'only what is like the name asked for');
            self::assertLessThan(strpos($e->getMessage(), 'claude-fable-5-1'), strpos($e->getMessage(), 'claude-fable-5-2'), 'the closest first');
        }
        try {
            $roles->check(ModelRole::STRONG, ModelRef::parse('claude:zzz'));
            self::fail('a model the catalog does not know was accepted');
        } catch (DomainException $e) {
            self::assertStringNotContainsString('claude-', $e->getMessage(), 'nothing alike — nothing is suggested');
        }
    }

    private static function info(string $id): ModelInfo {
        return new ModelInfo($id, $id, 100000, 0.000001, 0.000002, ['text'], ['text'], ['tools'], 4096);
    }
}
