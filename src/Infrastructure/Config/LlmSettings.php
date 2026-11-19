<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;

use SensitiveParameter;

/**
 * Model provider settings (OpenRouter, the OpenAI API, the Anthropic API, Claude in Amazon Bedrock). The route is VLESS when VLESS_URI is set, direct otherwise.
 *
 * spec: BRIEF.md#решения — route to the model through VLESS_URI
 */
final class LlmSettings {
    private const EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /** Defaults when OpenRouter has no key and only the OpenAI API is there. */
    private const OPENAI_MODEL = 'openai:gpt-6.1-terra';
    private const OPENAI_CHEAP_MODEL = 'openai:gpt-6-luna';

    /** Defaults when only the Anthropic API has a key. */
    private const ANTHROPIC_MODEL = 'anthropic:claude-sonnet-5-5';
    private const ANTHROPIC_CHEAP_MODEL = 'anthropic:claude-haiku-5-5';

    /** Defaults when only Bedrock has a key; us-east-1 serves every Claude model there. */
    private const BEDROCK_MODEL = 'bedrock:anthropic.claude-sonnet-5-5';
    private const BEDROCK_CHEAP_MODEL = 'bedrock:anthropic.claude-haiku-5-5';
    private const BEDROCK_REGION = 'us-east-1';

    private readonly ?string $openRouterKey;
    private readonly ?string $openAiKey;
    private readonly ?string $anthropicKey;
    private readonly ?string $bedrockKey;
    private readonly ?string $routeUri;

    /**
     * @param non-empty-string $model
     * @param 'none'|'minimal'|'low'|'medium'|'high'|'xhigh'|'max' $reasoningEffort
     * @param positive-int $maxTokens
     * @param positive-int $historyLimit
     * @param positive-int $contextWindow tokens of the model's window; the model catalog will replace it
     * @param non-empty-string $cheapModel model of auxiliary calls (compaction); the model roles will replace it
     */
    private function __construct(
        #[SensitiveParameter] ?string $openRouterKey,
        #[SensitiveParameter] ?string $openAiKey,
        #[SensitiveParameter] ?string $anthropicKey,
        #[SensitiveParameter] ?string $bedrockKey,
        #[SensitiveParameter] ?string $routeUri,
        public readonly string $model,
        public readonly string $reasoningEffort,
        public readonly int $maxTokens,
        public readonly int $historyLimit,
        public readonly int $contextWindow,
        public readonly string $cheapModel,
        /** AWS region of the Bedrock endpoint, checked to be a region name. */
        public readonly string $bedrockRegion = self::BEDROCK_REGION,
    ) {
        $this->openRouterKey = $openRouterKey;
        $this->openAiKey = $openAiKey;
        $this->anthropicKey = $anthropicKey;
        $this->bedrockKey = $bedrockKey;
        $this->routeUri = $routeUri;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — either key is enough, one of them is needed
        $openRouterKey = Env::raw($env, 'OPENROUTER_API_KEY');
        $openAiKey = Env::raw($env, 'OPENAI_API_KEY');
        $anthropicKey = Env::raw($env, 'ANTHROPIC_API_KEY');
        $bedrockKey = Env::raw($env, 'AWS_BEARER_TOKEN_BEDROCK');
        $providers = self::withKeys($openRouterKey, $openAiKey, $anthropicKey, $bedrockKey);
        if ($providers === []) {
            throw ConfigException::invalid('OPENROUTER_API_KEY', 'set it, OPENAI_API_KEY, ANTHROPIC_API_KEY or AWS_BEARER_TOKEN_BEDROCK: the agent needs at least one model provider');
        }
        // The region becomes a host name: only a region name may go there.
        $region = Env::raw($env, 'BEDROCK_REGION') ?? self::BEDROCK_REGION;
        if (preg_match('/^[a-z]{2}(-[a-z]+)+-\d{1,2}$/', $region) !== 1) {
            throw ConfigException::invalid('BEDROCK_REGION', 'an AWS region like us-east-1');
        }
        [$defaultModel, $defaultCheap] = match ($providers[0]) {
            ModelRef::OPENROUTER => ['z-ai/glm-5.3-flash', 'openai/gpt-5.6-luna'],
            ModelRef::OPENAI => [self::OPENAI_MODEL, self::OPENAI_CHEAP_MODEL],
            ModelRef::ANTHROPIC => [self::ANTHROPIC_MODEL, self::ANTHROPIC_CHEAP_MODEL],
            default => [self::BEDROCK_MODEL, self::BEDROCK_CHEAP_MODEL],
        };
        $model = self::model($env, 'LLM_MODEL', $defaultModel, $providers);
        $window = Env::positiveInt($env, 'LLM_CONTEXT_WINDOW', 131_072, 10_000_000);
        if ($window < 4096) {
            throw ConfigException::invalid('LLM_CONTEXT_WINDOW', 'at least 4096 tokens');
        }

        return new self(
            openRouterKey: $openRouterKey,
            openAiKey: $openAiKey,
            anthropicKey: $anthropicKey,
            bedrockKey: $bedrockKey,
            routeUri: Env::raw($env, 'VLESS_URI'),
            model: $model,
            reasoningEffort: self::effort(Env::oneOf($env, 'LLM_REASONING_EFFORT', self::EFFORTS, 'low')),
            maxTokens: Env::positiveInt($env, 'LLM_MAX_TOKENS', 4096, 200_000),
            historyLimit: Env::positiveInt($env, 'LLM_HISTORY_LIMIT', 40, 1000),
            contextWindow: $window,
            cheapModel: self::model($env, 'LLM_CHEAP_MODEL', $defaultCheap, $providers),
            bedrockRegion: $region,
        );
    }

    /**
     * @param array<array-key, mixed> $env
     * @param non-empty-string $default
     * @param list<string> $providers the providers with a key
     * @return non-empty-string
     * @throws ConfigException
     */
    private static function model(array $env, string $name, string $default, array $providers): string {
        $model = Env::raw($env, $name) ?? $default;
        try {
            $ref = ModelRef::parse($model);
        } catch (InvalidArgumentException) {
            throw ConfigException::invalid($name, 'a model like author/slug (OpenRouter) or provider:id');
        }
        if ($ref->provider === ModelRef::OPENROUTER && preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $ref->model) !== 1) {
            throw ConfigException::invalid($name, 'an OpenRouter model id like author/slug, or provider:id such as openai:gpt-6-luna');
        }
        if (!in_array($ref->provider, $providers, true)) {
            throw ConfigException::invalid($name, sprintf('a model of a provider with a key (%s), not %s', implode(', ', $providers), $ref->provider));
        }
        return $model;
    }

    /** @return 'none'|'minimal'|'low'|'medium'|'high'|'xhigh'|'max' */
    private static function effort(string $value): string {
        return match ($value) {
            'none' => 'none',
            'minimal' => 'minimal',
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
            default => 'max',
        };
    }

    public function openRouterKey(): ?string {
        return $this->openRouterKey;
    }

    public function openAiKey(): ?string {
        return $this->openAiKey;
    }

    public function anthropicKey(): ?string {
        return $this->anthropicKey;
    }

    /** Bedrock API key (a bearer token), AWS_BEARER_TOKEN_BEDROCK. */
    public function bedrockKey(): ?string {
        return $this->bedrockKey;
    }

    /** @return list<string> providers with a key, OpenRouter first */
    public function providers(): array {
        return self::withKeys($this->openRouterKey, $this->openAiKey, $this->anthropicKey, $this->bedrockKey);
    }

    /** @return list<string> */
    private static function withKeys(?string $openRouterKey, ?string $openAiKey, ?string $anthropicKey, ?string $bedrockKey): array {
        return array_keys(array_filter(
            [ModelRef::OPENROUTER => $openRouterKey, ModelRef::OPENAI => $openAiKey, ModelRef::ANTHROPIC => $anthropicKey, ModelRef::BEDROCK => $bedrockKey],
            static fn(?string $k): bool => $k !== null,
        ));
    }

    /** Outbound route for model requests; null means direct. */
    public function routeUri(): ?string {
        return $this->routeUri;
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array {
        return [
            'openRouterKey' => $this->openRouterKey === null ? null : '***',
            'openAiKey' => $this->openAiKey === null ? null : '***',
            'anthropicKey' => $this->anthropicKey === null ? null : '***',
            'bedrockKey' => $this->bedrockKey === null ? null : '***',
            'bedrockRegion' => $this->bedrockRegion,
            'route' => $this->routeUri === null ? 'direct' : 'vless',
            'model' => $this->model,
            'reasoningEffort' => $this->reasoningEffort,
            'maxTokens' => $this->maxTokens,
            'historyLimit' => $this->historyLimit,
            'contextWindow' => $this->contextWindow,
            'cheapModel' => $this->cheapModel,
        ];
    }
}
