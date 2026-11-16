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
