<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Media\MediaPrefs;
use OpenCCK\App\Agent\Tool\Files\MediaTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Infrastructure\Media\DocsService;
use OpenCCK\Infrastructure\Media\MediaModels;
use OpenCCK\Infrastructure\Media\VideoService;
use OpenCCK\Infrastructure\Services\ServiceModules;
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
    private function tool(array $env, array $responses = [], bool $video = false, bool $docs = false): MediaTool {
        $this->sandbox = new FakeSandbox();
        $this->http = new FakeHttpClient($responses);
        $settings = MediaSettings::fromEnv($env);
        $client = new HttpClient($this->http, []);
        $models = new MediaModels($client, 'or-key', null, $settings, static fn(string $n): ?string => null, new InMemoryLlmCallRepository());
        $modules = new ServiceModules($client);
        if ($video) {
            $modules->add(VideoService::MODULE, 'http://media-video:8091', 'token-0123456789abcdef');
        }
        if ($docs) {
            $modules->add(DocsService::MODULE, 'http://media-docs:8092', 'token-0123456789abcdef');
        }
        $modules->check();
        return new MediaTool($this->sandbox, $models, new MediaPrefs(new InMemoryMediaPrefRepository(), $settings), new VideoService($client, $modules), new DocsService($client, $modules));
    }

    private static function context(): ToolContext {
        return new ToolContext(3, 9, new Member(1, MemberKind::Human, Role::owner(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
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

    public function testAVideoWithoutAVideoModelGoesByFramesAndSpeechOfTheVideoModule(): void {
        $frames = json_encode(['seconds' => 4.0, 'frames' => [base64_encode('F1'), base64_encode('F2')], 'audio' => base64_encode('M4A')], JSON_THROW_ON_ERROR);
        $tool = $this->tool(
            ['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash', 'MEDIA_SPEECH_MODELS' => 'openrouter:openai/whisper-1'],
            [
                'https://openrouter.ai/api/v1/chat/completions' => self::chat('Собака бежит за мячом.'),
                'https://openrouter.ai/api/v1/audio/transcriptions' => [200, [], '{"text":"Апорт!"}'],
                'http://media-video:8091/health' => [200, [], '{"status":"ok"}'],
                'http://media-video:8091/frames?count=8' => [200, [], $frames],
            ],
            video: true,
        );
        $this->sandbox->files[1]['inbox/dog.mp4'] = 'MP4';

        $result = $tool->execute(['action' => 'video', 'path' => 'inbox/dog.mp4', 'question' => 'Что на видео?'], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Собака бежит за мячом.', $result->content);
        self::assertStringContainsString('Апорт!', $result->content);
        $toVideo = array_search('POST http://media-video:8091/frames?count=8', $this->http->requested, true);
        self::assertIsInt($toVideo);
        self::assertSame('MP4', $this->http->bodies[$toVideo], 'the video itself goes to the module');
        self::assertSame('Bearer token-0123456789abcdef', $this->http->headers[$toVideo]['authorization'] ?? null);
        $toVision = array_search('POST https://openrouter.ai/api/v1/chat/completions', $this->http->requested, true);
        self::assertIsInt($toVision);
        self::assertSame(2, substr_count($this->http->bodies[$toVision], '"type":"image_url"'), 'the frames go together to the vision model');
        self::assertSame(['fetch 1 inbox/dog.mp4'], $this->sandbox->calls, 'nothing runs in the sandbox, the video is only read');
    }

    public function testWithoutTheVideoModuleTheAgentIsToldHowToTurnItOn(): void {
        $tool = $this->tool(['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash']);
        $this->sandbox->files[1]['inbox/dog.mp4'] = 'MP4';

        $result = $tool->execute(['action' => 'video', 'path' => 'inbox/dog.mp4', 'question' => 'Что на видео?'], self::context());

        self::assertTrue($result->isError);
        self::assertStringContainsString('media-video', $result->content);
    }

    public function testTheTextOfADocumentFromWhereItStopped(): void {
        $tool = $this->tool(['MEDIA_TEXT_LIMIT' => '1000']);
        $this->sandbox->files[1]['inbox/long.txt'] = str_repeat('а', 1500) . 'КОНЕЦ';

        $result = $tool->execute(['action' => 'extract', 'path' => 'inbox/long.txt', 'offset' => 1000], self::context());

        self::assertStringContainsString('КОНЕЦ', $result->content);
        self::assertStringNotContainsString(str_repeat('а', 600), $result->content, 'from the offset on');
    }

    public function testAnOfficeDocumentFromWhereItStoppedByTheDocsModule(): void {
        $tool = $this->tool([], [
            'http://media-docs:8092/health' => [200, [], '{"status":"ok"}'],
            'http://media-docs:8092/extract?name=report.docx&limit=20010' => [200, [], json_encode(['text' => str_repeat('а', 10) . 'Вторая часть'], JSON_THROW_ON_ERROR)],
        ], docs: true);
        $this->sandbox->files[1]['inbox/report.docx'] = 'PK';

        $result = $tool->execute(['action' => 'extract', 'path' => 'inbox/report.docx', 'offset' => 10], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertStringStartsWith('Вторая часть', $result->content);
    }

    public function testAnOfficeDocumentWithoutTheDocsModule(): void {
        $tool = $this->tool([]);
        $this->sandbox->files[1]['inbox/report.docx'] = 'PK';

        $result = $tool->execute(['action' => 'extract', 'path' => 'inbox/report.docx'], self::context());

        self::assertTrue($result->isError);
        self::assertStringContainsString('media-docs', $result->content);
    }

    public function testTheModelsAndPrinciples(): void {
        $tool = $this->tool(['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash', 'MEDIA_VISION_RULES' => 'чеки — haiku']);

        $result = $tool->execute(['action' => 'models'], self::context());

        self::assertStringContainsString('openrouter:z-ai/glm-5.3-flash', $result->content);
        self::assertStringContainsString('чеки — haiku', $result->content);
    }
}
