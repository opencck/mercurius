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
     */
    private function __construct(
        #[SensitiveParameter] string $apiKey,
        #[SensitiveParameter] ?string $routeUri,
        public readonly string $model,
        public readonly string $reasoningEffort,
        public readonly int $maxTokens,
        public readonly int $historyLimit,
    ) {
        $this->apiKey = $apiKey;
        $this->routeUri = $routeUri;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $model = Env::raw($env, 'LLM_MODEL') ?? 'z-ai/glm-5.3-flash';
        if (!preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $model)) {
            throw ConfigException::invalid('LLM_MODEL', 'an OpenRouter model id like author/slug');
        }

        return new self(
            apiKey: Env::required($env, 'OPENROUTER_API_KEY'),
            routeUri: Env::raw($env, 'VLESS_URI'),
            model: $model,
            reasoningEffort: self::effort(Env::oneOf($env, 'LLM_REASONING_EFFORT', self::EFFORTS, 'low')),
            maxTokens: Env::positiveInt($env, 'LLM_MAX_TOKENS', 4096, 200_000),
            historyLimit: Env::positiveInt($env, 'LLM_HISTORY_LIMIT', 40, 1000),
        );
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
        ];
    }
}
