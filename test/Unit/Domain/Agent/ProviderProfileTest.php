<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Agent;

use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\PromptCache;
use OpenCCK\Domain\Agent\ProviderProfile;
use PHPUnit\Framework\TestCase;

final class ProviderProfileTest extends TestCase {
    public function testEveryProviderNamesItsKeyAndItsOwnModels(): void {
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — a provider is data: key, API, cache, models
        $names = [];
        foreach (ProviderProfile::all() as $name => $profile) {
            $names[] = $name;
            self::assertSame($name, $profile->name);
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', $profile->keyEnv);
            foreach ([$profile->model, $profile->cheapModel, ...array_values($profile->roles)] as $model) {
                self::assertSame($name, ModelRef::parse($model)->provider, $model . ' is a model of ' . $name);
            }
            self::assertSame([ModelRole::CHEAP, ModelRole::MAIN, ModelRole::STRONG, ModelRole::MEDIA], array_keys($profile->roles));
        }
        self::assertSame(['openrouter', 'openai', 'codex', 'anthropic', 'bedrock', 'gemini', 'vertex'], $names, 'OpenRouter first: the order of the defaults');
        self::assertSame('OPENAI_API_KEY', ProviderProfile::of('codex')?->keyEnv, 'one key, two APIs');
        // spec: brief/models.md#провайдеры-gemini-и-vertex
        self::assertSame('GEMINI_API_KEY', ProviderProfile::of('gemini')?->keyEnv);
        $vertex = ProviderProfile::of('vertex');
        self::assertNotNull($vertex);
        self::assertSame('VERTEX_CREDENTIALS', $vertex->keyEnv, 'the entry of Vertex is a service account');
        self::assertSame('vertex:google/gemini-3.8-flash', $vertex->cheapModel, 'Vertex spells models with the author');
        self::assertNull(ProviderProfile::of('unknown'));
    }

    public function testClaudeAnywhereIsCachedByMarksAndOpenAiByAKey(): void {
        self::assertSame(PromptCache::Marks, ProviderProfile::cacheFor(ModelRef::parse('anthropic/claude-opus-5')));
        self::assertSame(PromptCache::Marks, ProviderProfile::cacheFor(ModelRef::parse('anthropic:claude-opus-5-5')));
        self::assertSame(PromptCache::Marks, ProviderProfile::cacheFor(ModelRef::parse('bedrock:anthropic.claude-opus-5-5')));
        self::assertSame(PromptCache::Key, ProviderProfile::cacheFor(ModelRef::parse('openai:gpt-6-luna')));
        self::assertSame(PromptCache::Provider, ProviderProfile::cacheFor(ModelRef::parse('gemini:gemini-3.8-flash')), 'Gemini caches on its own');
        self::assertSame(PromptCache::Key, ProviderProfile::cacheFor(ModelRef::parse('codex:gpt-6.1-codex')));
        self::assertSame(PromptCache::Provider, ProviderProfile::cacheFor(ModelRef::parse('z-ai/glm-5.3-flash')));
        self::assertSame(PromptCache::Provider, ProviderProfile::cacheFor(ModelRef::parse('jev:jev-latest')), 'an unknown provider caches on its own');
    }
}
