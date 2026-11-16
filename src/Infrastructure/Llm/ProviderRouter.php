<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm;

use Amp\Cancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\StreamEvent;
use Mercurius\Llm\Usage;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Repository\ModelCatalogRepository;

/**
 * One model client for every provider: the provider is taken from the model ("anthropic:claude-x"), the request goes
 * to that provider's client with the bare id: OpenRouter and the OpenAI API, each only when it has a key.
 *
 * spec: BRIEF.md#модели — models are not only from OpenRouter
 */
final class ProviderRouter implements LlmClient {
    /**
     * @param array<string, LlmClient> $clients provider => client
     * @param ModelCatalogRepository|null $prices a provider that reports no cost (the OpenAI API) gets an estimate
     *        from catalog prices, so spend reports do not miss it
     */
    public function __construct(private readonly array $clients, private readonly ?ModelCatalogRepository $prices = null) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        [$client, $bare, $ref] = $this->route($request);
        return $this->settled($client->complete($bare, $cancellation), $ref);
    }

    /** @return \Generator<int, StreamEvent, mixed, void> */
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        [$client, $bare, $ref] = $this->route($request);
        foreach ($client->stream($bare, $cancellation) as $event) {
            yield $event instanceof StreamEnd ? new StreamEnd($this->settled($event->response, $ref)) : $event;
        }
    }

    /**
     * spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — a model name keeps its provider; OpenRouter's stays bare,
     * as before
     */
    private function settled(ChatResponse $response, ModelRef $ref): ChatResponse {
        $model = $ref->provider === ModelRef::OPENROUTER || $response->model === '' ? $response->model : $ref->provider . ':' . $response->model;
        $usage = $response->usage;
        if ($usage->costUsd === null && $this->prices !== null) {
            $estimate = $this->prices->find($ref)?->estimateCost($usage);
            if ($estimate !== null) {
                $usage = new Usage($usage->inputTokens, $usage->outputTokens, $usage->cacheReadTokens, $usage->cacheWriteTokens, $usage->reasoningTokens, $estimate);
            }
        }
        if ($model === $response->model && $usage === $response->usage) {
            return $response;
        }
        return new ChatResponse($response->text, $response->toolCalls, $response->finishReason, $usage, $model, $response->id, $response->reasoning);
    }

    /** @return array{LlmClient, ChatRequest, ModelRef} */
    private function route(ChatRequest $request): array {
        $ref = ModelRef::parse($request->model);
        $client = $this->clients[$ref->provider] ?? throw new LlmException(ErrorKind::BadRequest, sprintf('No client for the provider "%s"', $ref->provider));
        if ($ref->model === $request->model) {
            return [$client, $request, $ref];
        }
        return [$client, $request->withModel($ref->model), $ref];
    }
}
