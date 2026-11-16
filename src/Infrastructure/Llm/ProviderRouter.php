<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm;

use Amp\Cancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use OpenCCK\Domain\Agent\ModelRef;

/**
 * One model client for every provider: the provider is taken from the model ("anthropic:claude-x"), the request goes
 * to that provider's client with the bare id. Today only OpenRouter is wired; another provider is one more entry.
 *
 * spec: BRIEF.md#модели — models are not only from OpenRouter
 */
final class ProviderRouter implements LlmClient {
    /** @param array<string, LlmClient> $clients provider => client */
    public function __construct(private readonly array $clients) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        [$client, $bare] = $this->route($request);
        return $client->complete($bare, $cancellation);
    }

    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        [$client, $bare] = $this->route($request);
        return $client->stream($bare, $cancellation);
    }

    /** @return array{LlmClient, ChatRequest} */
    private function route(ChatRequest $request): array {
        $ref = ModelRef::parse($request->model);
        $client = $this->clients[$ref->provider] ?? throw new LlmException(ErrorKind::BadRequest, sprintf('No client for the provider "%s"', $ref->provider));
        if ($ref->model === $request->model) {
            return [$client, $request];
        }
        return [$client, new ChatRequest($ref->model, $request->messages, $request->tools, $request->maxTokens, $request->temperature, $request->reasoningEffort, $request->extra)];
    }
}
