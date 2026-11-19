<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm;

use Amp\Cancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Role;
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
        $bare = $ref->model === $request->model ? $request : $request->withModel($ref->model);
        // OpenRouter caches Anthropic prompts only when asked; one top-level field moves the breakpoint along the
        // conversation by itself, a mark on the system prompt keeps it cached when the history changes (a compaction).
        // The Anthropic API and Claude in Bedrock take the same two marks. OpenAI and most others cache on their own.
        // ref: references/hermes-agent/agent/agent_runtime_helpers.py@e96221fa:L1740-L1800 (anthropic_prompt_cache_policy)
        // ref: references/hermes-agent/agent/prompt_caching.py@e96221fa:L1-L5, L35-L47 (is_one_shot_request) — the
        //      system prompt is a breakpoint; a call nothing reads back from cache gets no mark at all
        $claude = in_array($ref->provider, [ModelRef::ANTHROPIC, ModelRef::BEDROCK], true) || ($ref->provider === ModelRef::OPENROUTER && str_starts_with($ref->model, 'anthropic/'));
        if ($claude && !isset($bare->extra['cache_control']) && !self::oneShot($bare)) {
            $messages = $bare->messages;
            if (isset($messages[0]) && $messages[0]->role === Role::System) {
                $messages[0] = $messages[0]->cached();
            }
            $bare = $bare->withMessages($messages)->withExtra([...$bare->extra, 'cache_control' => ['type' => 'ephemeral']]);
        }
        // ref: references/hermes-agent/agent/reasoning_params.py@e96221fa:L91-L122 (_supports_reasoning_extra_body) —
        //      the catalog's supported_parameters decide; the OpenAI API answers 400 to reasoning_effort for models
        //      without reasoning, where OpenRouter only ignored it. An unknown model keeps the effort.
        if ($bare->reasoningEffort !== null && $this->prices !== null) {
            $info = $this->prices->find($ref);
            if ($info !== null && array_intersect(['reasoning', 'reasoning_effort'], $info->supportedParameters) === []) {
                $bare = $bare->withReasoningEffort(null);
            }
        }
        return [$client, $bare, $ref];
    }

    /** No tools, no answers of the model, at most one user message: no later call reads its prefix from cache. */
    private static function oneShot(ChatRequest $request): bool {
        if ($request->tools !== []) {
            return false;
        }
        $users = 0;
        foreach ($request->messages as $message) {
            if ($message->role === Role::Assistant || $message->role === Role::Tool) {
                return false;
            }
            $users += $message->role === Role::User ? 1 : 0;
        }
        return $users <= 1;
    }
}
