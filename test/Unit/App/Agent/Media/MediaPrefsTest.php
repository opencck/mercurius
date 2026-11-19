<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Media;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Media\MediaPrefs;
use OpenCCK\App\Agent\Tool\Files\MediaPrefsTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Test\Support\InMemoryMediaPrefRepository;

/**
 * A participant decides for themself how each kind of file is handled: at once, on request or not at all, which of the
 * owner's models they prefer and their own principles of choosing — the owner's settings are only the default.
 */
final class MediaPrefsTest extends AsyncTestCase {
    private InMemoryMediaPrefRepository $repository;
    private MediaPrefs $prefs;
    private Principal $kostya;

    protected function setUp(): void {
        parent::setUp();
        $this->repository = new InMemoryMediaPrefRepository();
        $settings = MediaSettings::fromEnv(['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash, openrouter:anthropic/claude-haiku-5.5', 'MEDIA_AUTO' => 'voice,image,text,office']);
        $this->prefs = new MediaPrefs($this->repository, $settings);
        $this->kostya = new Principal(5, PrincipalKind::Human, Role::user(), 'Костя');
    }

    private function tool(): MediaPrefsTool {
        return new MediaPrefsTool($this->prefs);
    }

    private function context(): ToolContext {
        return new ToolContext(1, 1, $this->kostya, new NullCancellation());
    }

    public function testTheOwnersSettingsAreTheDefault(): void {
        self::assertSame('auto', $this->prefs->of(5, 'image')->mode);
        self::assertSame('demand', $this->prefs->of(5, 'audio')->mode, 'audio is not in MEDIA_AUTO here');
        self::assertNull($this->prefs->of(5, 'image')->model);
    }

    public function testAParticipantSetsTheirOwnWay(): void {
        $result = $this->tool()->execute(['action' => 'set', 'kind' => 'image', 'mode' => 'demand', 'model' => 'openrouter:anthropic/claude-haiku-5.5', 'rules' => 'чеки и документы — только haiku'], $this->context());

        self::assertFalse($result->isError, $result->content);
        $pref = $this->prefs->of(5, 'image');
        self::assertSame(['demand', 'openrouter:anthropic/claude-haiku-5.5', 'чеки и документы — только haiku'], [$pref->mode, $pref->model, $pref->rules]);
        self::assertSame('auto', $this->prefs->of(6, 'image')->mode, 'another participant keeps the default');
        self::assertSame(DangerClass::Write, $this->tool()->danger(['action' => 'set']));
        self::assertStringContainsString('чеки и документы', $this->tool()->execute(['action' => 'show'], $this->context())->content);
    }

    public function testOnlyTheOwnersModelsAndKnownKinds(): void {
        self::assertTrue($this->tool()->execute(['action' => 'set', 'kind' => 'image', 'model' => 'openai:gpt-9-ultra'], $this->context())->isError, 'participants do not add models: the owner pays');
        self::assertTrue($this->tool()->execute(['action' => 'set', 'kind' => 'sticker', 'mode' => 'off'], $this->context())->isError);
        self::assertTrue($this->tool()->execute(['action' => 'set', 'kind' => 'image', 'mode' => 'always'], $this->context())->isError);
    }

    public function testAnEmptyValueReturnsToTheDefault(): void {
        $this->tool()->execute(['action' => 'set', 'kind' => 'voice', 'mode' => 'off', 'rules' => 'не расшифровывай'], $this->context());
        $this->tool()->execute(['action' => 'set', 'kind' => 'voice', 'mode' => '', 'rules' => ''], $this->context());

        self::assertSame(['auto', ''], [$this->prefs->of(5, 'voice')->mode, $this->prefs->of(5, 'voice')->rules]);
    }
}
