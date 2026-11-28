<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * A model provider as data: the API it speaks, the key it needs, how it caches prompts and which of its models play
 * the roles by default. A new provider is a new profile here and a client in the provider factory; the rest of the
 * agent reads the profile.
 *
 * ref: references/hermes-agent/providers/base.py@e96221fa:L140-L171 (ProviderProfile) — identity, API mode, key
 *      variables and endpoint per provider; hermes also keeps auth handlers and vision flags there, which here stay in
 *      the clients and the catalog.
 *
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — a provider is data
 */
final class ProviderProfile {
    public const API_CHAT_COMPLETIONS = 'chat_completions';
    public const API_RESPONSES = 'responses';
    public const API_MESSAGES = 'messages';

    /** @var array<string, self>|null */
    private static ?array $all = null;

    /**
     * @param self::API_* $api
     * @param non-empty-string $model default model of the agent when this is the first provider with a key
     * @param non-empty-string $cheapModel default model of auxiliary calls
     * @param array<string, non-empty-string> $roles seed models of the roles when this is the first provider with a key
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $api,
        public readonly string $keyEnv,
        public readonly PromptCache $cache,
        public readonly string $model,
        public readonly string $cheapModel,
        public readonly array $roles,
    ) {}

    /**
     * All providers, in the order their defaults are preferred: OpenRouter first.
     *
     * @return array<string, self>
     */
    public static function all(): array {
        if (self::$all !== null) {
            return self::$all;
        }
        $claude = static fn(string $p): array => [
            ModelRole::CHEAP => $p . 'claude-haiku-5-5',
            ModelRole::MAIN => $p . 'claude-sonnet-5-5',
            ModelRole::STRONG => $p . 'claude-opus-5-5',
            ModelRole::MEDIA => $p . 'claude-haiku-5-5',
        ];
        $openAi = static fn(string $p): array => [
            ModelRole::CHEAP => $p . 'gpt-6-luna',
            ModelRole::MAIN => $p . 'gpt-6.1-terra',
            // gpt-6.1-sol always reasons: in Chat Completions it takes no tools
            ModelRole::STRONG => $p . 'gpt-6-sol',
            ModelRole::MEDIA => $p . 'gpt-6-luna',
        ];
        // spec: brief/models.md#провайдеры-gemini-и-vertex — Flash for the cheap and the media role, the Gemini 4 models above
        $gemini = static fn(string $p): array => [
            ModelRole::CHEAP => $p . 'gemini-3.8-flash',
            ModelRole::MAIN => $p . 'gemini-4-hydrogen',
            ModelRole::STRONG => $p . 'gemini-4-carbon',
            ModelRole::MEDIA => $p . 'gemini-3.8-flash',
        ];
        // spec: brief/models.md#kimi-ключ-api-и-подписка — K3.1 Pro above, K3 for the cheap and the media role; the K2 models
        // of the platform leave at the end of December 2026
        $moonshot = [
            ModelRole::CHEAP => 'moonshot:kimi-k3',
            ModelRole::MAIN => 'moonshot:kimi-k3.1-pro',
            ModelRole::STRONG => 'moonshot:kimi-k3.1-pro',
            ModelRole::MEDIA => 'moonshot:kimi-k3',
        ];
        $kimi = [
            ModelRole::CHEAP => 'kimi:k3',
            ModelRole::MAIN => 'kimi:kimi-for-coding',
            ModelRole::STRONG => 'kimi:k3.1-pro',
            ModelRole::MEDIA => 'kimi:k3',
        ];
        $all = [];
        foreach ([
            new self(ModelRef::OPENROUTER, 'OpenRouter', self::API_CHAT_COMPLETIONS, 'OPENROUTER_API_KEY', PromptCache::Provider, 'openrouter:z-ai/glm-5.3-flash', 'openrouter:openai/gpt-5.6-luna', [
                // spec: brief/models.md#начальная-таблица-ролей-моделей
                ModelRole::CHEAP => 'openrouter:openai/gpt-5.6-luna',
                ModelRole::MAIN => 'openrouter:z-ai/glm-5.3-flash',
                ModelRole::STRONG => 'openrouter:anthropic/claude-opus-5',
                ModelRole::MEDIA => 'openrouter:google/gemini-3.8-flash',
            ]),
            new self(ModelRef::OPENAI, 'OpenAI', self::API_CHAT_COMPLETIONS, 'OPENAI_API_KEY', PromptCache::Key, 'openai:gpt-6.1-terra', 'openai:gpt-6-luna', $openAi('openai:')),
            new self(ModelRef::CODEX, 'OpenAI Responses', self::API_RESPONSES, 'OPENAI_API_KEY', PromptCache::Key, 'codex:gpt-6.1-terra', 'codex:gpt-6-luna', $openAi('codex:')),
            new self(ModelRef::ANTHROPIC, 'Anthropic', self::API_MESSAGES, 'ANTHROPIC_API_KEY', PromptCache::Marks, 'anthropic:claude-sonnet-5-5', 'anthropic:claude-haiku-5-5', $claude('anthropic:')),
            new self(ModelRef::BEDROCK, 'Amazon Bedrock', self::API_MESSAGES, 'AWS_BEARER_TOKEN_BEDROCK', PromptCache::Marks, 'bedrock:anthropic.claude-sonnet-5-5', 'bedrock:anthropic.claude-haiku-5-5', $claude('bedrock:anthropic.')),
            new self(ModelRef::GEMINI, 'Google Gemini', self::API_CHAT_COMPLETIONS, 'GEMINI_API_KEY', PromptCache::Provider, 'gemini:gemini-4-hydrogen', 'gemini:gemini-3.8-flash', $gemini('gemini:')),
            // the "key" of Vertex is the JSON of a service account
            new self(ModelRef::VERTEX, 'Google Vertex', self::API_CHAT_COMPLETIONS, 'VERTEX_CREDENTIALS', PromptCache::Provider, 'vertex:google/gemini-4-hydrogen', 'vertex:google/gemini-3.8-flash', $gemini('vertex:google/')),
            new self(ModelRef::MOONSHOT, 'Kimi Platform', self::API_CHAT_COMPLETIONS, 'MOONSHOT_API_KEY', PromptCache::Provider, 'moonshot:kimi-k3.1-pro', 'moonshot:kimi-k3', $moonshot),
            // the subscription: a key of the Kimi Code console, paid by the plan
            new self(ModelRef::KIMI, 'Kimi Code', self::API_CHAT_COMPLETIONS, 'KIMI_CODE_API_KEY', PromptCache::Provider, 'kimi:kimi-for-coding', 'kimi:k3', $kimi),
        ] as $profile) {
            $all[$profile->name] = $profile;
        }
        return self::$all = $all;
    }

    public static function of(string $provider): ?self {
        return self::all()[$provider] ?? null;
    }

    /**
     * How the prefix of a request to this model is cached. Claude through OpenRouter is cached like Claude anywhere;
     * an unknown provider caches on its own.
     */
    public static function cacheFor(ModelRef $ref): PromptCache {
        // ref: references/hermes-agent/agent/agent_runtime_helpers.py@e96221f:L1769-L1776 — Kimi through OpenRouter takes
        //      the markers of Claude; without them about 1% of the prompt is read from the cache
        if ($ref->provider === ModelRef::OPENROUTER && (str_starts_with($ref->model, 'anthropic/') || str_starts_with($ref->model, 'moonshotai/'))) {
            return PromptCache::Marks;
        }
        return self::of($ref->provider)->cache ?? PromptCache::Provider;
    }
}
