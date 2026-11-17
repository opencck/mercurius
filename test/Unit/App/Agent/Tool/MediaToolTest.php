<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Media\MediaPrefs;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Tool\MediaTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Infrastructure\Media\MediaModels;
use OpenCCK\Test\Support\FakeHttpClient;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryMediaPrefRepository;

/**
 * The agent asks about details of a file in its sandbox: a question to a picture, the text of speech, a question to a
 * video (by a video model, or by frames and speech), the text of a document from where it stopped; with the model the
 * principles call for, only among the owner's.
 */
final class MediaToolTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private FakeHttpClient $http;

    /**
     * @param array<string, string> $env
     * @param array<string, array{int, array<non-empty-string, string>, string}> $responses
     */
    private function tool(array $env, array $responses = []): MediaTool {
        $this->sandbox = new FakeSandbox();
        $this->http = new FakeHttpClient($responses);
        $settings = MediaSettings::fromEnv($env);
        $models = new MediaModels(new HttpClient($this->http, []), 'or-key', null, $settings, static fn(string $n): ?string => null, new InMemoryLlmCallRepository());
        return new MediaTool($this->sandbox, $models, new MediaPrefs(new InMemoryMediaPrefRepository(), $settings));
    }

    private static function context(): ToolContext {
        return new ToolContext(3, 9, new Principal(1, PrincipalKind::Human, Role::Owner, null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    /** @return array{int, array<non-empty-string, string>, string} */
    private static function chat(string $text): array {
        return [200, [], json_encode(['choices' => [['message' => ['content' => $text]]]], JSON_THROW_ON_ERROR)];
    }

    public function testAQuestionToAPictureWithTheModelThePrinciplesCallFor(): void {
        $tool = $this->tool(['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash, openrouter:anthropic/claude-haiku-5.5'], ['https://openrouter.ai/api/v1/chat/completions' => self::chat('Итого: 1200 ₽.')]);
        $this->sandbox->files[1]['inbox/2026-11-17/receipt.jpg'] = 'JPEG';

        $result = $tool->execute(['action' => 'look', 'path' => 'inbox/2026-11-17/receipt.jpg', 'question' => 'Какая сумма в чеке?', 'model' => 'openrouter:anthropic/claude-haiku-5.5'], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Итого: 1200 ₽.', $result->content);
        self::assertStringContainsString('anthropic/claude-haiku-5.5', $this->http->bodies[0], 'the model asked for goes first');
        self::assertStringContainsString('Какая сумма в чеке?', $this->http->bodies[0]);
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'look']));
        self::assertTrue($tool->untrusted());
    }

    public function testOnlyTheOwnersModels(): void {
        $tool = $this->tool(['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash']);
        $this->sandbox->files[1]['a.jpg'] = 'JPEG';

        self::assertTrue($tool->execute(['action' => 'look', 'path' => 'a.jpg', 'question' => '?', 'model' => 'openai:gpt-9'], self::context())->isError);
        self::assertSame([], $this->http->bodies);
    }

    public function testSpeechOfAFile(): void {
        $tool = $this->tool(['MEDIA_SPEECH_MODELS' => 'openrouter:openai/whisper-1'], ['https://openrouter.ai/api/v1/audio/transcriptions' => [200, [], '{"text":"Длинная лекция о котах."}']]);
        $this->sandbox->files[1]['inbox/lecture.mp3'] = 'MP3';

        self::assertStringContainsString('Длинная лекция о котах.', $tool->execute(['action' => 'transcribe', 'path' => 'inbox/lecture.mp3'], self::context())->content);
    }

    public function testAVideoWithoutAVideoModelGoesByFramesAndSpeech(): void {
        $tool = $this->tool(
            ['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash', 'MEDIA_SPEECH_MODELS' => 'openrouter:openai/whisper-1'],
            ['https://openrouter.ai/api/v1/chat/completions' => self::chat('Собака бежит за мячом.'), 'https://openrouter.ai/api/v1/audio/transcriptions' => [200, [], '{"text":"Апорт!"}']],
        );
        $this->sandbox->files[1]['inbox/dog.mp4'] = 'MP4';
        // what video_frames.sh leaves: frames and the sound
        $this->sandbox->files[1]['.media/frames/frame-01.jpg'] = 'F1';
        $this->sandbox->files[1]['.media/frames/frame-02.jpg'] = 'F2';
        $this->sandbox->files[1]['.media/frames/audio.m4a'] = 'M4A';
        $this->sandbox->outcome = new CommandOutcome(0, "audio.m4a\nframe-01.jpg\nframe-02.jpg\n", '', false, false, 10);

        $result = $tool->execute(['action' => 'video', 'path' => 'inbox/dog.mp4', 'question' => 'Что на видео?'], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Собака бежит за мячом.', $result->content);
        self::assertStringContainsString('Апорт!', $result->content);
        self::assertSame(2, substr_count($this->http->bodies[0], '"type":"image_url"'), 'the frames go together to the vision model');
        self::assertNotEmpty(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'video_frames.sh')));
    }

    public function testTheTextOfADocumentFromWhereItStopped(): void {
        $tool = $this->tool(['MEDIA_TEXT_LIMIT' => '1000']);
        $this->sandbox->files[1]['inbox/long.txt'] = str_repeat('а', 1500) . 'КОНЕЦ';

        $result = $tool->execute(['action' => 'extract', 'path' => 'inbox/long.txt', 'offset' => 1000], self::context());

        self::assertStringContainsString('КОНЕЦ', $result->content);
        self::assertStringNotContainsString(str_repeat('а', 600), $result->content, 'from the offset on');
    }

    public function testTheModelsAndPrinciples(): void {
        $tool = $this->tool(['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash', 'MEDIA_VISION_RULES' => 'чеки — haiku']);

        $result = $tool->execute(['action' => 'models'], self::context());

        self::assertStringContainsString('openrouter:z-ai/glm-5.3-flash', $result->content);
        self::assertStringContainsString('чеки — haiku', $result->content);
    }
}
