<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Media;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Infrastructure\Media\MediaCall;
use OpenCCK\Infrastructure\Media\MediaModels;
use OpenCCK\Test\Support\FakeHttpClient;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;

/**
 * Vision, speech and video models over OpenAI-compatible APIs: OpenRouter, OpenAI or an endpoint of the owner's own;
 * the next model of the list when one fails; the spend recorded like any model call.
 */
final class MediaModelsTest extends AsyncTestCase {
    private InMemoryLlmCallRepository $calls;
    private FakeHttpClient $http;

    /** @param array<string, array{int, array<non-empty-string, string>, string}> $responses */
    private function models(array $responses = [], ?string $openAiKey = null): MediaModels {
        $http = $this->http = new FakeHttpClient($responses);
        $this->calls = new InMemoryLlmCallRepository();
        $settings = MediaSettings::fromEnv(['MEDIA_ENDPOINTS' => 'whisper=http://whisper:8000/v1']);
        return new MediaModels(new HttpClient($http, []), 'or-key', $openAiKey, $settings, static fn(string $name): ?string => $name === 'whisper' ? 'w-key' : null, $this->calls);
    }

    private static function call(): MediaCall {
        return new MediaCall(7, 70, new NullCancellation());
    }

    /** @return array{int, array<non-empty-string, string>, string} */
    private static function chat(string $text, float $cost = 0.0001): array {
        return [200, ['content-type' => 'application/json'], json_encode(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 60, 'cost' => $cost]], JSON_THROW_ON_ERROR)];
    }

    public function testAPictureGoesAsAnImagePartAndTheSpendIsRecorded(): void {
        $models = $this->models(['https://openrouter.ai/api/v1/chat/completions' => self::chat('На фото кот.')]);

        $answer = $models->look('openrouter:z-ai/glm-5.3-flash', [['bytes' => 'JPEG', 'mime' => 'image/jpeg']], 'Опиши кратко.', self::call());

        self::assertSame(['На фото кот.', null], [$answer->text, $answer->error]);
        /** @var array{model: string, messages: list<array{content: list<array<string, mixed>>}>} $body */
        $body = json_decode($this->http->bodies[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('z-ai/glm-5.3-flash', $body['model']);
        self::assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('JPEG')]], $body['messages'][0]['content'][1]);
        self::assertSame('Bearer or-key', $this->http->headers[0]['authorization'] ?? null);
        $record = $this->calls->calls[0];
        self::assertSame(['media-vision', 'openrouter:z-ai/glm-5.3-flash', 1200, 60, 0.0001, 7, 70], [$record->purpose, $record->model, $record->inputTokens, $record->outputTokens, $record->costUsd, $record->conversationId, $record->runId]);
    }

    private static function used(\OpenCCK\Domain\Repository\RunRepository $runs, int $id): ?int {
        return $runs->find($id)?->tokensUsed;
    }

    public function testAPictureARunLookedAtCountsInTheTokensOfThatRun(): void {
        // spec: brief/operations.md#расход-что-знает-агент — the tokens of a run are all the tokens its calls spent
        $models = $this->models(['https://openrouter.ai/api/v1/chat/completions' => self::chat('На фото кот.')]);
        $runs = new \OpenCCK\Test\Support\InMemoryRunRepository();
        $models->useRuns($runs);
        $run = $runs->start(7, 1, 'test/model', new \OpenCCK\Domain\Agent\RunLimits(30, 100_000, 900, 120)) ?? self::fail('no run');

        $models->look('openrouter:z-ai/glm-5.3-flash', [['bytes' => 'JPEG', 'mime' => 'image/jpeg']], 'Опиши кратко.', new MediaCall(7, $run->id, new NullCancellation()));
        self::assertSame(1260, self::used($runs, $run->id));

        // a picture that came before any run belongs to no run
        $models->look('openrouter:z-ai/glm-5.3-flash', [['bytes' => 'JPEG', 'mime' => 'image/jpeg']], 'Опиши кратко.', new MediaCall(7, null, new NullCancellation()));
        self::assertSame(1260, self::used($runs, $run->id));
    }

    public function testAPictureIsDrawnByAnOpenRouterImageModelAndItsCostRecorded(): void {
        // spec: brief/models.md#категории-моделей
        $models = $this->models(['https://openrouter.ai/api/v1/images' => [200, ['content-type' => 'application/json'], json_encode([
            'data' => [['b64_json' => base64_encode('PNG'), 'media_type' => 'image/png']],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 4175, 'cost' => 0.04],
        ], JSON_THROW_ON_ERROR)]]);

        $drawn = $models->draw('openrouter:bytedance-seed/seedream-4.5', 'кот в шляпе', '1:1', self::call());

        self::assertNull($drawn->error);
        self::assertSame('PNG', $drawn->images[0]->bytes ?? null);
        /** @var array<string, mixed> $body */
        $body = json_decode($this->http->bodies[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['bytedance-seed/seedream-4.5', 'кот в шляпе', '1:1'], [$body['model'] ?? null, $body['prompt'] ?? null, $body['aspect_ratio'] ?? null]);
        $record = $this->calls->calls[0];
        self::assertSame(['media-image', 'openrouter:bytedance-seed/seedream-4.5', 4175, 0.04], [$record->purpose, $record->model, $record->outputTokens, $record->costUsd]);

        $failed = $this->models([])->draw('openrouter:x/y', 'кот', null, self::call());
        self::assertSame([], $failed->images);
        self::assertNotNull($failed->error, 'no answer queued: the error is the reason');
    }

    public function testAClaudeModelLooksThroughTheClientOfItsProvider(): void {
        $claude = new \OpenCCK\Test\Support\ScriptedLlm([\OpenCCK\Test\Support\ScriptedLlm::text('Кот.')]);
        $this->calls = new InMemoryLlmCallRepository();
        $models = new MediaModels(new HttpClient(new FakeHttpClient([]), []), null, null, MediaSettings::fromEnv([]), static fn(string $n): ?string => null, $this->calls, clients: ['anthropic' => $claude]);

        $answer = $models->look('anthropic:claude-haiku-5-5', [['bytes' => 'JPEG', 'mime' => 'image/jpeg']], 'Что на фото?', self::call());

        self::assertSame('Кот.', $answer->text);
        self::assertSame('claude-haiku-5-5', $claude->requests[0]->model);
        self::assertSame('image/jpeg', $claude->requests[0]->messages[0]->media[0]->mime ?? null);
        self::assertSame('media-vision', $this->calls->calls[0]->purpose);
    }

    public function testSpeechIsAFileUploadToItsEndpoint(): void {
        $models = $this->models(['http://whisper:8000/v1/audio/transcriptions' => [200, [], '{"text":"Привет, это голосовое."}']]);

        $answer = $models->transcribe('custom:whisper/large-v3', 'OGG', 'voice-7.ogg', self::call());

        self::assertSame('Привет, это голосовое.', $answer->text);
        self::assertSame('Bearer w-key', $this->http->headers[0]['authorization'] ?? null);
        self::assertStringContainsString('name="model"', $this->http->bodies[0]);
        self::assertStringContainsString('large-v3', $this->http->bodies[0]);
        self::assertStringContainsString('filename="voice-7.ogg"', $this->http->bodies[0]);
        self::assertSame('media-speech', $this->calls->calls[0]->purpose);
    }

    public function testTheNextModelWhenOneFailsAndEveryReasonWhenAllDo(): void {
        $models = $this->models([
            'https://openrouter.ai/api/v1/chat/completions' => [400, [], '{"error":{"message":"No endpoints found that support image input"}}'],
            'https://api.openai.com/v1/chat/completions' => self::chat('Чек на 1200 ₽.'),
        ], 'oa-key');

        $answer = $models->first(['openrouter:x/text-only', 'openai:gpt-6-luna'], static fn(string $ref) => $models->look($ref, [['bytes' => 'J', 'mime' => 'image/png']], 'Что тут?', self::call()));

        self::assertSame(['Чек на 1200 ₽.', 'openai:gpt-6-luna'], [$answer->text, $answer->model]);
        /** @var array<string, mixed> $openAi */
        $openAi = json_decode($this->http->bodies[1], true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('max_completion_tokens', $openAi, 'OpenAI takes max_completion_tokens');

        $models = $this->models(['https://openrouter.ai/api/v1/chat/completions' => [500, [], 'oops']]);
        $failed = $models->first(['openrouter:a/b', 'openai:gpt-6-luna'], static fn(string $ref) => $models->look($ref, [['bytes' => 'J', 'mime' => 'image/png']], 'Что тут?', self::call()));
        self::assertNull($failed->text);
        self::assertStringContainsString('HTTP 500', $failed->error ?? '');
        self::assertStringContainsString('OPENAI_API_KEY', $failed->error ?? '', 'no key — no request, and the reason says so');
        self::assertCount(2, $this->http->bodies, 'a server error is asked once more, as for the text models');
    }

    public function testAVideoGoesAsAVideoPart(): void {
        $models = $this->models(['https://openrouter.ai/api/v1/chat/completions' => self::chat('На видео собака бежит.')]);

        $answer = $models->video('google/gemini-3.8-flash', 'MP4', 'video/mp4', 'Что происходит?', self::call());

        self::assertSame('На видео собака бежит.', $answer->text);
        /** @var array{messages: list<array{content: list<array{type: string}>}>} $body */
        $body = json_decode($this->http->bodies[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('video_url', $body['messages'][0]['content'][1]['type']);
        self::assertSame('media-video', $this->calls->calls[0]->purpose);
    }
}
