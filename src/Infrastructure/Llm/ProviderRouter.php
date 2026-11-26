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
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\PromptCache;
use OpenCCK\Domain\Agent\ProviderProfile;
use OpenCCK\Domain\Repository\ModelCatalogRepository;

/**
 * One model client for every provider: the provider is taken from the model ("anthropic:claude-x"), the request goes
 * to that provider's client with the bare id: OpenRouter and the OpenAI API, each only when it has a key.
 *
 * spec: brief/agent-loop.md#модели — models are not only from OpenRouter
 */
final class ProviderRouter implements LlmClient {
    /**
     * @param array<string, LlmClient> $clients provider => client
     * @param ModelCatalogRepository|null $prices a provider that reports no cost (the OpenAI API) gets an estimate
     *        from catalog prices, so spend reports do not miss it
     * @param ProviderPauses|null $pauses providers off the choice for a while: a call to one fails at once
     */
    public function __construct(
        private readonly array $clients,
        private readonly ?ModelCatalogRepository $prices = null,
        private readonly ?ProviderPauses $pauses = null,
    ) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        [$client, $bare, $ref] = $this->route($request);
        try {
            return $this->settled($client->complete($bare, $cancellation), $ref);
        } catch (LlmException $e) {
            throw $this->noted($e, $ref);
        }
    }

    /** @return \Generator<int, StreamEvent, mixed, void> */
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        [$client, $bare, $ref] = $this->route($request);
        try {
            foreach ($client->stream($bare, $cancellation) as $event) {
                yield $event instanceof StreamEnd ? new StreamEnd($this->settled($event->response, $ref)) : $event;
            }
        } catch (LlmException $e) {
            throw $this->noted($e, $ref);
        }
    }

    /** seconds a plan whose provider asks the owner to act stays off the choice: until the owner lifts it */
    private const ACCOUNT_PAUSE = 30 * 86400;

    /**
     * A used-up window of a plan takes the provider off the choice until it reopens; a subscription that lost its
     * sign-in — until the owner signs in again (it is asked again in ten minutes, in case the sign-in came back).
     *
     * spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории
     */
    private function noted(LlmException $e, ModelRef $ref): LlmException {
        if ($this->pauses === null) {
            return $e;
        }
        if ($e->resetsAt !== null) {
            // a window may be the model's own (a week of Opus): the other models of the provider go on
            $this->pauses->pause((string) $ref, $e->resetsAt, ProviderPauses::LIMIT);
        } elseif ($e->status === 403 && $ref->provider === ModelRef::ANTIGRAVITY) {
            // spec: brief/models.md#google-по-подписке — Google asks the owner of the account to act: the plan is not
            // called again until the owner says so (/antigravity resume), not asked again in ten minutes
            $this->pauses->pauseFor($ref->provider, self::ACCOUNT_PAUSE, ProviderPauses::ACCOUNT);
        } elseif ($e->kind === ErrorKind::Auth && in_array($ref->provider, ModelRef::SUBSCRIPTIONS, true)) {
            $this->pauses->pauseFor($ref->provider, 600, ProviderPauses::SIGN_IN);
        }
        return $e;
    }

    /**
     * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — a model name keeps its provider; OpenRouter's stays bare,
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
        return new ChatResponse($response->text, $response->toolCalls, $response->finishReason, $usage, $model, $response->id, $response->reasoning, $response->requestId, $response->refusalCategory, $response->refusalExplanation);
    }

    /** @return array{LlmClient, ChatRequest, ModelRef} */
    private function route(ChatRequest $request): array {
        $ref = ModelRef::parse($request->model);
        $client = $this->clients[$ref->provider] ?? throw new LlmException(ErrorKind::BadRequest, sprintf('No client for the provider "%s"', $ref->provider));
        $pause = $this->pauses?->blocking($ref);
        if ($pause !== null) {
            // no request: the provider would refuse the same way, and a subscription might bill it beyond the plan
            throw match ($pause['why']) {
                ProviderPauses::LIMIT => new LlmException(ErrorKind::RateLimited, sprintf('%s: the limit of the plan is used up until %s', $ref->provider, gmdate('Y-m-d H:i', $pause['until']) . ' UTC'), 429, null, null, $pause['until']),
                ProviderPauses::ACCOUNT => new LlmException(ErrorKind::Auth, sprintf('%s: the provider of the plan asks the owner of the account to act', $ref->provider), 403),
                default => new LlmException(ErrorKind::Auth, sprintf('%s: the owner is not signed in to the subscription', $ref->provider), 401),
            };
        }
        $bare = $ref->model === $request->model ? $request : $request->withModel($ref->model);
        // OpenRouter caches Anthropic prompts only when asked; one top-level field moves the breakpoint along the
        // conversation by itself, a mark on the system prompt keeps it cached when the history changes (a compaction).
        // The Anthropic API and Claude in Bedrock take the same two marks. OpenAI and most others cache on their own.
        // ref: references/hermes-agent/agent/agent_runtime_helpers.py@e96221fa:L1740-L1800 (anthropic_prompt_cache_policy)
        // ref: references/hermes-agent/agent/prompt_caching.py@e96221fa:L1-L5, L35-L47 (is_one_shot_request) — the
        //      system prompt is a breakpoint; a call nothing reads back from cache gets no mark at all
        if (ProviderProfile::cacheFor($ref) === PromptCache::Marks && !isset($bare->extra['cache_control']) && !self::oneShot($bare)) {
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
