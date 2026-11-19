<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Llm;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use OpenCCK\Infrastructure\Llm\ProviderRouter;
use OpenCCK\Test\Support\ScriptedLlm;

final class ProviderRouterTest extends AsyncTestCase {
    public function testRequestGoesToTheProviderWithoutThePrefix(): void {
        $openRouter = new ScriptedLlm([ScriptedLlm::text('из openrouter')]);
        $anthropic = new ScriptedLlm([ScriptedLlm::text('из anthropic')]);
        $router = new ProviderRouter(['openrouter' => $openRouter, 'anthropic' => $anthropic]);

        $router->complete(new ChatRequest('anthropic:claude-x', [Message::user('привет')]));
        foreach ($router->stream(new ChatRequest('z-ai/glm', [Message::user('привет')])) as $event) {
        }

        self::assertSame('claude-x', $anthropic->requests[0]->model);
        self::assertSame('z-ai/glm', $openRouter->requests[0]->model);
    }

    public function testProviderWithoutCostGetsAnEstimateAndKeepsItsName(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $stores->replace('openai', [new \Mercurius\Llm\ModelCatalog\ModelInfo('gpt-6-luna', 'luna', 1_000_000, 0.000001, 0.000002)]);
        $openAi = new ScriptedLlm([
            new \Mercurius\Llm\ChatResponse('ок', [], \Mercurius\Llm\FinishReason::Stop, new \Mercurius\Llm\Usage(1000, 100), 'gpt-6-luna-2026-09-22', 'c1'),
            new \Mercurius\Llm\ChatResponse('ок', [], \Mercurius\Llm\FinishReason::Stop, new \Mercurius\Llm\Usage(1000, 100), 'gpt-6-luna-2026-09-22', 'c2'),
        ]);
        $router = new ProviderRouter(['openai' => $openAi, 'openrouter' => new ScriptedLlm()], $stores);

        $response = $router->complete(new ChatRequest('openai:gpt-6-luna', [Message::user('привет')]));
        $end = null;
        foreach ($router->stream(new ChatRequest('openai:gpt-6-luna', [Message::user('привет')])) as $event) {
            $end = $event;
        }

        self::assertEqualsWithDelta(0.0012, $response->usage->costUsd, 1e-9);
        self::assertSame('openai:gpt-6-luna-2026-09-22', $response->model);
        self::assertInstanceOf(\Mercurius\Llm\StreamEnd::class, $end);
        self::assertEqualsWithDelta(0.0012, $end->response->usage->costUsd, 1e-9);
    }

    public function testOpenRouterKeepsItsBareNameAndItsCost(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $stores->replace('openrouter', [new \Mercurius\Llm\ModelCatalog\ModelInfo('a/m', 'm', 1000, 0.001, 0.002)]);
        $openRouter = new ScriptedLlm([new \Mercurius\Llm\ChatResponse('ок', [], \Mercurius\Llm\FinishReason::Stop, new \Mercurius\Llm\Usage(10, 1, costUsd: 0.5), 'a/m', 'g')]);
        $router = new ProviderRouter(['openrouter' => $openRouter], $stores);

        $response = $router->complete(new ChatRequest('a/m', [Message::user('привет')]));

        self::assertSame('a/m', $response->model);
        self::assertSame(0.5, $response->usage->costUsd, 'a reported cost is not replaced');
    }

    public function testReasoningGoesOnlyToModelsThatTakeIt(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $stores->replace('openai', [
            new \Mercurius\Llm\ModelCatalog\ModelInfo('gpt-4o', '4o', 128_000, 0.000001, 0.000002, ['text'], ['text'], ['tools', 'max_tokens']),
            new \Mercurius\Llm\ModelCatalog\ModelInfo('gpt-6-luna', 'luna', 1_000_000, 0.000001, 0.000002, ['text'], ['text'], ['tools', 'reasoning', 'reasoning_effort']),
        ]);
        $openAi = new ScriptedLlm([ScriptedLlm::text('a'), ScriptedLlm::text('b'), ScriptedLlm::text('c')]);
        $router = new ProviderRouter(['openai' => $openAi], $stores);

        $router->complete(new ChatRequest('openai:gpt-4o', [Message::user('x')], reasoningEffort: 'low'));
        $router->complete(new ChatRequest('openai:gpt-6-luna', [Message::user('x')], reasoningEffort: 'low'));
        $router->complete(new ChatRequest('openai:unknown', [Message::user('x')], reasoningEffort: 'low'));

        self::assertNull($openAi->requests[0]->reasoningEffort);
        self::assertSame('low', $openAi->requests[1]->reasoningEffort);
        self::assertSame('low', $openAi->requests[2]->reasoningEffort, 'unknown models keep it');
    }

    public function testClaudeThroughOpenRouterAsksForPromptCaching(): void {
        $openRouter = new ScriptedLlm([ScriptedLlm::text('a'), ScriptedLlm::text('b'), ScriptedLlm::text('c')]);
        $router = new ProviderRouter(['openrouter' => $openRouter]);
        $conversation = [Message::system('правила'), Message::user('x'), Message::assistant('y'), Message::user('z')];

        $router->complete(new ChatRequest('anthropic/claude-opus-5', $conversation));
        $router->complete(new ChatRequest('z-ai/glm-5.3-flash', $conversation));
        $router->complete(new ChatRequest('anthropic/claude-opus-5', [Message::system('сожми'), Message::user('текст')]));

        self::assertSame(['type' => 'ephemeral'], $openRouter->requests[0]->extra['cache_control'] ?? null);
        self::assertTrue($openRouter->requests[0]->messages[0]->cacheMark, 'the system prompt is a breakpoint of its own');
        self::assertFalse($openRouter->requests[0]->messages[3]->cacheMark);
        self::assertArrayNotHasKey('cache_control', $openRouter->requests[1]->extra, 'others cache on their own');
        self::assertFalse($openRouter->requests[1]->messages[0]->cacheMark);
        // a one-shot call (no tools, no history) would only pay for a cache write nothing reads
        self::assertArrayNotHasKey('cache_control', $openRouter->requests[2]->extra);
        self::assertFalse($openRouter->requests[2]->messages[0]->cacheMark);
    }

    public function testClaudeThroughTheAnthropicApiAsksForPromptCachingToo(): void {
        // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai
        $anthropic = new ScriptedLlm([ScriptedLlm::text('a')]);
        $router = new ProviderRouter(['anthropic' => $anthropic]);

        $router->complete(new ChatRequest('anthropic:claude-opus-5-5', [Message::system('правила'), Message::user('x'), Message::assistant('y'), Message::user('z')]));

        self::assertSame('claude-opus-5-5', $anthropic->requests[0]->model);
        self::assertSame(['type' => 'ephemeral'], $anthropic->requests[0]->extra['cache_control'] ?? null);
        self::assertTrue($anthropic->requests[0]->messages[0]->cacheMark);
    }

    public function testUnknownProviderIsABadRequest(): void {
        $router = new ProviderRouter(['openrouter' => new ScriptedLlm()]);

        try {
            $router->complete(new ChatRequest('openai:gpt-x', [Message::user('привет')]));
            self::fail('no error');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::BadRequest, $e->kind);
        }
    }
}
