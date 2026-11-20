<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm\ChatGpt;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\OpenAI\ResponsesClient;
use Mercurius\Llm\StreamEnd;

/**
 * Models of the owner's ChatGPT subscription (prefix chatgpt:): the Responses API at chatgpt.com/backend-api/codex with
 * the access token of CodexAccount and its account headers. The backend answers only as a stream and refuses
 * max_output_tokens and temperature, so they are left out and a whole answer is collected from the stream.
 *
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — Codex through the owner's ChatGPT subscription
 * ref: references/hermes-agent/agent/transports/codex.py@e96221fa:L421-L422 (sampling knobs dropped), L868-L880
 *      (no max_output_tokens on the Codex backend); agent/codex_headers.py@e96221fa:L36-L55 (identity headers) — the
 *      agent names itself mercurius, as hermes names itself
 */
final class CodexClient implements LlmClient {
    public const BASE_URL = 'https://chatgpt.com/backend-api/codex';

    public function __construct(
        private readonly HttpClient $http,
        private readonly CodexAccount $account,
        private readonly string $version = 'dev',
    ) {}

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        foreach ($this->stream($request, $cancellation) as $event) {
            if ($event instanceof StreamEnd) {
                return $event->response;
            }
        }
        throw new LlmException(ErrorKind::Server, 'ChatGPT ended the answer without its end');
    }

    /** @return \Generator<int, \Mercurius\Llm\StreamEvent, mixed, void> */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator {
        $token = $this->account->accessToken($cancellation);
        $headers = ['User-Agent' => 'Mercurius/' . $this->version, 'originator' => 'mercurius'] + CodexAccount::accountHeaders($token);
        $client = new ResponsesClient(new HttpClient(new HeadersAdded($this->http, $headers), []), $token, self::BASE_URL);
        yield from $client->stream(self::forCodex($request), $cancellation);
    }

    /** The request without what the Codex backend refuses. */
    public static function forCodex(ChatRequest $request): ChatRequest {
        return new ChatRequest($request->model, $request->messages, $request->tools, null, null, $request->reasoningEffort, $request->extra);
    }
}

/** Adds fixed headers to every request before the client sends it. */
final class HeadersAdded implements DelegateHttpClient {
    /** @param array<non-empty-string, string> $headers */
    public function __construct(private readonly DelegateHttpClient $client, private readonly array $headers) {}

    public function request(Request $request, Cancellation $cancellation): Response {
        foreach ($this->headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        return $this->client->request($request, $cancellation);
    }
}
