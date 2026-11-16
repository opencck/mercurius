<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use LogicException;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;

use function Amp\delay;

/**
 * Answers each call with the next scripted response; when the script ends, answers with plain text "готово".
 */
final class ScriptedLlm implements LlmClient {
    /** @var list<ChatRequest> */
    public array $requests = [];
    /** seconds every call waits before answering; cancellable */
    public float $delay = 0.0;

    /** @param list<ChatResponse|LlmException> $script */
    public function __construct(private array $script = []) {}

    public static function text(string $text, int $input = 100, int $output = 10): ChatResponse {
        return new ChatResponse($text, [], FinishReason::Stop, new Usage($input, $output), 'test/model', 'gen');
    }

    public static function calls(ToolCall ...$calls): ChatResponse {
        return new ChatResponse('', array_values($calls), FinishReason::ToolCalls, new Usage(100, 10), 'test/model', 'gen');
    }

    public static function truncated(ToolCall ...$calls): ChatResponse {
        return new ChatResponse('', array_values($calls), FinishReason::Length, new Usage(100, 10), 'test/model', 'gen');
    }

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        throw new LogicException('The loop streams');
    }

    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        $this->requests[] = $request;
        if ($this->delay > 0) {
            delay($this->delay, cancellation: $cancellation);
        }
        $next = array_shift($this->script) ?? self::text('готово');
        if ($next instanceof LlmException) {
            throw $next;
        }
        if ($next->text !== '') {
            yield new TextDelta($next->text);
        }
        yield new StreamEnd($next);
    }
}
