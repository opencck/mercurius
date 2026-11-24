<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelListEditor;
use OpenCCK\App\Agent\Model\ModelLists;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Tool\Self\ModelListTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemorySettingRepository;

use function Mercurius\I18n\t;

/**
 * The lists of models by category change in the dialog: the agent edits them at the owner's word, a text list works
 * at once, and a model the owner names for a role joins its list instead of being refused.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 */
final class ModelListEditTest extends AsyncTestCase {
    private InMemoryModelStores $catalog;
    private SettingsService $settings;
    private ModelLists $lists;
    private ModelRoles $roles;
    private ModelListEditor $editor;
    private Principal $owner;
    private float $now = 1000.0;

    protected function setUp(): void {
        parent::setUp();
        $this->catalog = new InMemoryModelStores();
        $this->catalog->replace('openrouter', [
            self::info('author/a'), self::info('author/b'), self::info('author/c'),
            new ModelInfo('author/notools', 'author/notools', 100000, 0.000001, 0.000002, ['text'], ['text'], [], 4096),
        ]);
        $principals = new InMemoryPrincipalRepository();
        $this->owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $owner = $this->owner;
        $this->settings = new SettingsService(new InMemorySettingRepository(), new Vault(new InMemorySecretRepository(), random_bytes(32)), ['LLM_MAIN_MODELS' => 'author/a'], static fn(array $env): ?string => null, static fn(): Principal => $owner);
        $this->lists = ModelLists::live(fn(string $setting): ?string => $this->settings->current($setting), 'author/a', fn(): float => $this->now);
        $this->roles = new ModelRoles($this->catalog, $this->catalog, ['openrouter'], null, $this->lists);
        $this->roles->seed();
        $this->editor = $editor = new ModelListEditor($this->settings, $this->lists, $this->roles);
        $this->roles->widenWith(static function (string $role, string $model, Principal $by) use ($editor): void {
            $editor->change($by, $role, 'add', [$model]);
        });
    }

    private static function info(string $id): ModelInfo {
        return new ModelInfo($id, $id, 100000, 0.000001, 0.000002, ['text'], ['text'], ['tools'], 4096);
    }

    private function later(): void {
        $this->now += ModelLists::REREAD + 1.0;
    }

    public function testAPaidModelIsAddedWithAWordAboutTheSameOneByTheSubscription(): void {
        $this->catalog->replace('openrouter', [self::info('author/a'), self::info('anthropic/claude-sonnet-4.5'), self::info('z-ai/glm-5.3-flash')]);
        $this->catalog->replace('claude', [self::info('claude-opus-5-5'), self::info('claude-sonnet-5-5'), self::info('claude-haiku-5-5')]);
        $roles = new ModelRoles($this->catalog, $this->catalog, ['openrouter', 'claude'], null, $this->lists);
        $editor = new ModelListEditor($this->settings, $this->lists, $roles);

        $said = $editor->change($this->owner, ModelRole::CHEAP, 'add', ['anthropic/claude-sonnet-4.5']);
        self::assertStringContainsString(sprintf(t('settings.saved_live'), 'LLM_CHEAP_MODELS'), $said, 'the model the agent named is added all the same');
        self::assertStringContainsString('claude:claude-sonnet-5-5', $said, 'and the agent learns the subscription has the same model');
        self::assertStringNotContainsString('claude-opus', $said);

        self::assertSame(sprintf(t('settings.saved_live'), 'LLM_CHEAP_MODELS'), $editor->change($this->owner, ModelRole::CHEAP, 'add', ['z-ai/glm-5.3-flash']), 'nothing to say about a model the subscription has not');
        self::assertSame(sprintf(t('settings.saved_live'), 'LLM_CHEAP_MODELS'), $editor->change($this->owner, ModelRole::CHEAP, 'add', ['claude:claude-sonnet-5-5']), 'nor about the model of the subscription itself');
    }

    public function testATextListWorksAtOnceWithoutARestart(): void {
        self::assertSame(['openrouter:author/a'], array_map('strval', $this->lists->of(ModelRole::MAIN)));

        $said = $this->editor->change($this->owner, ModelRole::MAIN, 'add', ['author/b']);

        self::assertSame(sprintf(t('settings.saved_live'), 'LLM_MAIN_MODELS'), $said);
        self::assertSame(['openrouter:author/a', 'openrouter:author/b'], array_map('strval', $this->lists->of(ModelRole::MAIN)), 'the editor sees its own change at once');
        $this->settings->set($this->owner, 'LLM_MAIN_MODELS', 'author/c');
        $this->later();
        self::assertSame(['openrouter:author/c'], array_map('strval', $this->lists->of(ModelRole::MAIN)), '/set too works without /restart');
    }

    public function testTheEditorChecksTheModelsOfATextCategory(): void {
        foreach ([['openrouter:author/missing', 'model.model_roles.modeli_net_v_kataloge_provaydera'], ['author/notools', 'model.model_roles.model_ne_vyzyvaet_instrumenty_a']] as [$model, $why]) {
            try {
                $this->editor->change($this->owner, ModelRole::MAIN, 'add', [$model]);
                self::fail($model . ' was taken');
            } catch (DomainException $e) {
                self::assertStringStartsWith(explode('%', t($why))[0], $e->getMessage());
            }
        }
        self::assertSame(sprintf(t('settings.saved_live'), 'LLM_CHEAP_MODELS'), $this->editor->change($this->owner, ModelRole::CHEAP, 'set', ['author/notools']), 'the cheap role calls no tools');
        $this->expectException(DomainException::class);
        $this->editor->change($this->owner, ModelRole::MAIN, 'remove', ['author/a']);
    }

    public function testAModelTheOwnerNamesForARoleJoinsItsList(): void {
        $change = $this->roles->set(ModelRole::MAIN, 'author/b', 'по просьбе владельца', $this->owner, false);

        self::assertSame('openrouter:author/b', (string) $change->model);
        self::assertSame('openrouter:author/b', (string) $this->roles->model(ModelRole::MAIN));
        self::assertTrue($this->lists->allows(ModelRole::MAIN, \OpenCCK\Domain\Agent\ModelRef::parse('author/b')));

        $agent = new Principal(2, PrincipalKind::Agent, Role::admin(), 'agent');
        try {
            $this->roles->set(ModelRole::MAIN, 'author/c', 'хочу другую модель', $agent, false);
            self::fail('another participant widened the list');
        } catch (DomainException $e) {
            self::assertStringContainsString('LLM_MAIN_MODELS', $e->getMessage());
        }
    }

    public function testTheToolEditsTheListsOfEveryCategoryOnlyForTheOwner(): void {
        $tool = new ModelListTool($this->editor);
        $owner = new ToolContext($this->owner->id, 1, $this->owner, new NullCancellation());
        $guest = new Principal(9, PrincipalKind::Human, Role::user(), null);

        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'list']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'add', 'category' => 'main', 'models' => ['author/b']]));
        self::assertStringContainsString('LLM_MAIN_MODELS: openrouter:author/a', $tool->execute(['action' => 'list'], $owner)->content);

        $vision = $tool->execute(['action' => 'set', 'category' => 'vision', 'models' => ['openrouter:author/eyes']], $owner);
        self::assertFalse($vision->isError, $vision->content);
        self::assertSame(sprintf(t('settings.saved_live'), 'MEDIA_VISION_MODELS'), $vision->content, 'a list of media works at once, like a text one');
        self::assertSame('openrouter:author/eyes', $this->settings->current('MEDIA_VISION_MODELS'));

        self::assertFalse($tool->execute(['action' => 'add', 'category' => 'strong', 'models' => ['author/c']], $owner)->isError);
        self::assertSame(['openrouter:author/a', 'openrouter:author/c'], array_map('strval', $this->lists->of(ModelRole::STRONG)), 'an unfilled list starts from the starting model');
        self::assertTrue($tool->execute(['action' => 'add', 'category' => 'main', 'models' => ['author/b']], new ToolContext(9, 2, $guest, new NullCancellation()))->isError);
    }
}
