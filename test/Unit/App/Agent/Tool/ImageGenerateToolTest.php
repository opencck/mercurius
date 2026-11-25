<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Files\ImageGenerateTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Infrastructure\Media\MediaModels;
use OpenCCK\Test\Support\FakeHttpClient;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;

/**
 * The agent draws a picture with an image model of the owner's list and gets it as a file of its sandbox, to send
 * with send_file or to look at.
 */
final class ImageGenerateToolTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private FakeHttpClient $http;

    /** @param array<string, array{int, array<non-empty-string, string>, string}> $responses */
    private function tool(array $responses): ImageGenerateTool {
        $this->sandbox = new FakeSandbox();
        $this->http = new FakeHttpClient($responses);
        $settings = MediaSettings::fromEnv(['MEDIA_IMAGE_MODELS' => 'openrouter:bytedance-seed/seedream-4.5, openrouter:google/gemini-3.8-image', 'MEDIA_IMAGE_RULES' => 'обычные — seedream']);
        $models = new MediaModels(new HttpClient($this->http, []), 'or-key', null, $settings, static fn(string $n): ?string => null, new InMemoryLlmCallRepository());
        return new ImageGenerateTool($this->sandbox, $models, $settings);
    }

    private static function context(): ToolContext {
        return new ToolContext(3, 9, new Member(1, MemberKind::Human, Role::admin(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    public function testThePictureLandsInTheSandboxUnderItsName(): void {
        // spec: brief/models.md#категории-моделей — image generation
        $tool = $this->tool(['https://openrouter.ai/api/v1/images' => [200, [], json_encode(['data' => [['b64_json' => base64_encode('PNG'), 'media_type' => 'image/png']]], JSON_THROW_ON_ERROR)]]);

        $result = $tool->execute(['prompt' => 'кот в шляпе', 'name' => 'кот в шляпе!', 'aspect_ratio' => '1:1'], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame('PNG', $this->sandbox->files[1]['images/kot-v-shlyape.png'] ?? null);
        self::assertStringContainsString('images/kot-v-shlyape.png', $result->content);
        self::assertStringContainsString('openrouter:bytedance-seed/seedream-4.5', $result->content, 'the first model of the list');
        self::assertSame(DangerClass::Write, $tool->danger([]));
        self::assertStringContainsString('обычные — seedream', $tool->definition()->description, 'the owner\'s principles come with the tool');
    }

    public function testOnlyAModelOfTheListDrawsAndAFailureSaysWhy(): void {
        $tool = $this->tool([]);

        $other = $tool->execute(['prompt' => 'кот', 'model' => 'openrouter:openai/gpt-image-2'], self::context());
        self::assertTrue($other->isError);
        self::assertStringContainsString('seedream-4.5', $other->content, 'the list is named');
        self::assertSame([], $this->http->requested, 'a model outside the list is not called');

        $failed = $tool->execute(['prompt' => 'кот'], self::context());
        self::assertTrue($failed->isError);
        self::assertCount(2, $this->http->requested, 'the next model when one fails');
        self::assertSame([], $this->sandbox->files[1] ?? [], 'nothing is written without a picture');
    }
}
