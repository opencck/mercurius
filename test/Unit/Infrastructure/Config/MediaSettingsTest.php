<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Config;

use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\MediaSettings;
use PHPUnit\Framework\TestCase;

/**
 * Media models are unset by default; the owner names them per kind (OpenRouter, OpenAI, an endpoint of their own).
 */
final class MediaSettingsTest extends TestCase {
    public function testEverythingIsUnsetByDefault(): void {
        $media = MediaSettings::fromEnv([]);

        self::assertSame([[], [], []], [$media->models('vision'), $media->models('video'), $media->models('speech')]);
        self::assertSame([], $media->endpoints);
        self::assertSame(['voice', 'audio', 'image', 'text', 'office'], $media->auto);
        self::assertSame([10, 20_000, 30], [$media->audioAutoMinutes, $media->textLimit, $media->inboxDays]);
    }

    public function testModelReferencesAreChecked(): void {
        $media = MediaSettings::fromEnv([
            'MEDIA_ENDPOINTS' => 'whisper=http://whisper:8000/v1, local=https://ollama.example/v1',
            'MEDIA_VISION_MODELS' => 'openrouter:google/gemini-3.8-flash, google/gemini-3.8-flash',
            'MEDIA_SPEECH_MODELS' => 'openai:gpt-4o-transcribe,custom:whisper/large-v3',
        ]);

        self::assertSame(['whisper' => 'http://whisper:8000/v1', 'local' => 'https://ollama.example/v1'], $media->endpoints);
        self::assertSame(['openrouter:google/gemini-3.8-flash', 'google/gemini-3.8-flash'], $media->models('vision'));
        self::assertSame(['openai:gpt-4o-transcribe', 'custom:whisper/large-v3'], $media->models('speech'));
        foreach (['custom:nope/x', 'ftp:x', 'openai:', 'custom:whisper'] as $bad) {
            try {
                MediaSettings::fromEnv(['MEDIA_ENDPOINTS' => 'whisper=http://whisper:8000/v1', 'MEDIA_SPEECH_MODELS' => $bad]);
                self::fail($bad . ' was taken');
            } catch (ConfigException $e) {
                self::assertStringContainsString('MEDIA_SPEECH_MODELS', $e->getMessage());
            }
        }
        $this->expectException(ConfigException::class);
        MediaSettings::fromEnv(['MEDIA_ENDPOINTS' => 'whisper=file:///etc/passwd']);
    }

    public function testEveryProviderOfAProfileServesTheKindsAndOpenRouterDraws(): void {
        // spec: BRIEF.md#категории-моделей — a category is a list of models of any provider, or none
        $media = MediaSettings::fromEnv([
            'MEDIA_VISION_MODELS' => 'anthropic:claude-haiku-5-5, bedrock:anthropic.claude-haiku-5-5, codex:gpt-6-luna',
            'MEDIA_IMAGE_MODELS' => 'openrouter:bytedance-seed/seedream-4.5, google/gemini-3.8-image',
            'MEDIA_IMAGE_RULES' => 'обычные картинки — seedream',
        ]);

        self::assertSame(['anthropic:claude-haiku-5-5', 'bedrock:anthropic.claude-haiku-5-5', 'codex:gpt-6-luna'], $media->models('vision'));
        self::assertSame(['openrouter:bytedance-seed/seedream-4.5', 'google/gemini-3.8-image'], $media->models('image'));
        self::assertSame('обычные картинки — seedream', $media->rules('image'));
        self::assertSame([], MediaSettings::fromEnv([])->models('image'), 'none by default');
        foreach (['MEDIA_VISION_MODELS' => 'nope:model', 'MEDIA_IMAGE_MODELS' => 'openai:gpt-image-2'] as $key => $bad) {
            try {
                MediaSettings::fromEnv([$key => $bad]);
                self::fail($bad . ' was taken');
            } catch (ConfigException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testAutoKnowsOnlyTheKinds(): void {
        self::assertSame(['voice', 'image'], MediaSettings::fromEnv(['MEDIA_AUTO' => 'voice, image'])->auto);
        self::assertSame([], MediaSettings::fromEnv(['MEDIA_AUTO' => 'none'])->auto);

        $this->expectException(ConfigException::class);
        MediaSettings::fromEnv(['MEDIA_AUTO' => 'voice,photo']);
    }

    public function testThresholdsAreBounded(): void {
        $this->expectException(ConfigException::class);
        MediaSettings::fromEnv(['MEDIA_TEXT_LIMIT' => '5']);
    }

    public function testThePrinciplesOfTheOwnerPerKind(): void {
        $media = MediaSettings::fromEnv(['MEDIA_VISION_RULES' => 'обычные фото — glm; чеки — haiku']);

        self::assertSame('обычные фото — glm; чеки — haiku', $media->rules('vision'));
        self::assertSame('', $media->rules('speech'));

        $this->expectException(ConfigException::class);
        MediaSettings::fromEnv(['MEDIA_SPEECH_RULES' => str_repeat('а', 1001)]);
    }
}
