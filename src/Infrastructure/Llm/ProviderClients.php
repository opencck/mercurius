<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm;

use Amp\Http\Client\HttpClient;
use Closure;
use Mercurius\Llm\Anthropic\AnthropicClient;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\ModelCatalog\AnthropicCatalog;
use Mercurius\Llm\ModelCatalog\BedrockCatalog;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use Mercurius\Llm\ModelCatalog\OpenAICatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\OpenAI\OpenAIClient;
use Mercurius\Llm\OpenAI\ResponsesClient;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\RetryingClient;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ProviderProfile;
use OpenCCK\Infrastructure\Config\LlmSettings;

/**
 * One client and one catalog per provider with a key, built from its profile; the model's prefix picks the client.
 * The catalogs of the direct APIs that list ids only are described by the public OpenRouter catalog.
 *
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
 */
final class ProviderClients {
    /**
     * @param array<string, LlmClient> $clients provider => client, with retries
     * @param array<string, Closure(): list<ModelInfo>> $catalogs provider => loader of its models
     * @param array<string, Closure(): list<string>> $toolReliable provider => the ids of the models that call tools well
     */
    private function __construct(
        public readonly array $clients,
        public readonly array $catalogs,
        public readonly array $toolReliable,
    ) {}

    /**
     * @param (Closure(LlmException, int, float): void)|null $onRetry
     */
    public static function build(LlmSettings $settings, HttpClient $http, ?string $appUrl, ?Closure $onRetry = null): self {
        $clients = [];
        $catalogs = [];
        $toolReliable = [];
        $reference = new OpenRouterCatalog($http);
        foreach (array_keys(ProviderProfile::all()) as $name) {
            $key = $settings->key($name);
            if ($key === null) {
                continue;
            }
            [$client, $catalog] = match ($name) {
                ModelRef::OPENROUTER => [
                    new OpenRouterClient($http, $key, appName: 'Mercurius', appUrl: $appUrl),
                    (new OpenRouterCatalog($http, $key))->fetch(...),
                ],
                ModelRef::OPENAI => [new OpenAIClient($http, $key), (new OpenAICatalog($http, $key, $reference))->fetch(...)],
                // the same key through the Responses API: the codex models and the tools of models that always reason
                ModelRef::CODEX => [new ResponsesClient($http, $key), (new OpenAICatalog($http, $key, $reference, responses: true))->fetch(...)],
                ModelRef::ANTHROPIC => [new AnthropicClient($http, $key), (new AnthropicCatalog($http, $key, $reference))->fetch(...)],
                ModelRef::BEDROCK => [AnthropicClient::bedrock($http, $key, $settings->bedrockRegion), (new BedrockCatalog($reference))->fetch(...)],
                default => throw new \LogicException('No client for the provider profile ' . $name),
            };
            $clients[$name] = new RetryingClient($client, onRetry: $onRetry);
            $catalogs[$name] = $catalog;
            if ($name === ModelRef::OPENROUTER) {
                // spec: brief/models.md#выбор-моделей-под-задачу — a model must pass 90% of its tool calls by the catalog to get a part with tools
                $toolReliable[$name] = static fn(): array => (new OpenRouterCatalog($http, $key))->toolReliable(0.9);
            }
        }
        return new self($clients, $catalogs, $toolReliable);
    }
}
