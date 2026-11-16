<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;

use SensitiveParameter;

/**
 * Model provider settings (OpenRouter). The route is VLESS when VLESS_URI is set, direct otherwise.
 *
 * spec: BRIEF.md#решения — route to the model through VLESS_URI
 */
final class LlmSettings {
    private const EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /** Defaults when OpenRouter has no key and only the OpenAI API is there. */
    private const OPENAI_MODEL = 'openai:gpt-6.1-terra';
    private const OPENAI_CHEAP_MODEL = 'openai:gpt-6-luna';

    private readonly ?string $openRouterKey;
    private readonly ?string $openAiKey;
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
        #[SensitiveParameter] ?string $routeUri,
        public readonly string $model,
        public readonly string $reasoningEffort,
        public readonly int $maxTokens,
        public readonly int $historyLimit,
        public readonly int $contextWindow,
        public readonly string $cheapModel,
    ) {
        $this->openRouterKey = $openRouterKey;
        $this->openAiKey = $openAiKey;
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
        if ($openRouterKey === null && $openAiKey === null) {
            throw ConfigException::invalid('OPENROUTER_API_KEY', 'set it or OPENAI_API_KEY: the agent needs at least one model provider');
        }
        $providers = array_keys(array_filter([ModelRef::OPENROUTER => $openRouterKey, ModelRef::OPENAI => $openAiKey], static fn(?string $k): bool => $k !== null));
        $openRouter = $openRouterKey !== null;
        $model = self::model($env, 'LLM_MODEL', $openRouter ? 'z-ai/glm-5.3-flash' : self::OPENAI_MODEL, $providers);
        $window = Env::positiveInt($env, 'LLM_CONTEXT_WINDOW', 131_072, 10_000_000);
        if ($window < 4096) {
            throw ConfigException::invalid('LLM_CONTEXT_WINDOW', 'at least 4096 tokens');
        }

        return new self(
            openRouterKey: $openRouterKey,
            openAiKey: $openAiKey,
            routeUri: Env::raw($env, 'VLESS_URI'),
            model: $model,
            reasoningEffort: self::effort(Env::oneOf($env, 'LLM_REASONING_EFFORT', self::EFFORTS, 'low')),
            maxTokens: Env::positiveInt($env, 'LLM_MAX_TOKENS', 4096, 200_000),
            historyLimit: Env::positiveInt($env, 'LLM_HISTORY_LIMIT', 40, 1000),
            contextWindow: $window,
            cheapModel: self::model($env, 'LLM_CHEAP_MODEL', $openRouter ? 'openai/gpt-5.6-luna' : self::OPENAI_CHEAP_MODEL, $providers),
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
            throw ConfigException::invalid($name, 'an OpenRouter model id like author/slug');
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

    /** @return list<string> providers with a key, OpenRouter first */
    public function providers(): array {
        return array_keys(array_filter([ModelRef::OPENROUTER => $this->openRouterKey, ModelRef::OPENAI => $this->openAiKey], static fn(?string $k): bool => $k !== null));
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
