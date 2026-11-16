<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\Usage;

/**
 * Streams the given chunks, then a final response; or throws the given error.
 */
final class FakeLlm implements LlmClient {
    /** @var list<ChatRequest> */
    public array $requests = [];

    /** @param list<string> $chunks */
    public function __construct(
        private readonly array $chunks = ['Привет', ', мир'],
        private readonly ?LlmException $error = null,
        private readonly Usage $usage = new Usage(10, 5, 0, 0, 0, 0.0001),
    ) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        $this->requests[] = $request;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->response($request);
    }

    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        $this->requests[] = $request;
        if ($this->error !== null) {
            throw $this->error;
        }
        foreach ($this->chunks as $chunk) {
            yield new TextDelta($chunk);
        }
        yield new StreamEnd($this->response($request));
    }

    private function response(ChatRequest $request): ChatResponse {
        return new ChatResponse(implode('', $this->chunks), [], FinishReason::Stop, $this->usage, $request->model, 'gen-1');
    }
}
