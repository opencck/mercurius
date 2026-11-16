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
