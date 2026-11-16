<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * Model provider settings (OpenRouter). The route is VLESS when VLESS_URI is set, direct otherwise.
 *
 * spec: BRIEF.md#решения — route to the model through VLESS_URI
 */
final class LlmSettings {
    private const EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    private readonly string $apiKey;
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
        #[SensitiveParameter] string $apiKey,
        #[SensitiveParameter] ?string $routeUri,
        public readonly string $model,
        public readonly string $reasoningEffort,
        public readonly int $maxTokens,
        public readonly int $historyLimit,
        public readonly int $contextWindow,
        public readonly string $cheapModel,
    ) {
        $this->apiKey = $apiKey;
        $this->routeUri = $routeUri;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $model = self::model($env, 'LLM_MODEL', 'z-ai/glm-5.3-flash');
        $window = Env::positiveInt($env, 'LLM_CONTEXT_WINDOW', 131_072, 10_000_000);
        if ($window < 4096) {
            throw ConfigException::invalid('LLM_CONTEXT_WINDOW', 'at least 4096 tokens');
        }

        return new self(
            apiKey: Env::required($env, 'OPENROUTER_API_KEY'),
            routeUri: Env::raw($env, 'VLESS_URI'),
            model: $model,
            reasoningEffort: self::effort(Env::oneOf($env, 'LLM_REASONING_EFFORT', self::EFFORTS, 'low')),
            maxTokens: Env::positiveInt($env, 'LLM_MAX_TOKENS', 4096, 200_000),
            historyLimit: Env::positiveInt($env, 'LLM_HISTORY_LIMIT', 40, 1000),
            contextWindow: $window,
            cheapModel: self::model($env, 'LLM_CHEAP_MODEL', 'openai/gpt-5.6-luna'),
        );
    }

    /**
     * @param array<array-key, mixed> $env
     * @param non-empty-string $default
     * @return non-empty-string
     * @throws ConfigException
     */
    private static function model(array $env, string $name, string $default): string {
        $model = Env::raw($env, $name) ?? $default;
        if (preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $model) !== 1) {
            throw ConfigException::invalid($name, 'an OpenRouter model id like author/slug');
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

    public function apiKey(): string {
        return $this->apiKey;
    }

    /** Outbound route for model requests; null means direct. */
    public function routeUri(): ?string {
        return $this->routeUri;
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array {
        return [
            'apiKey' => '***',
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
