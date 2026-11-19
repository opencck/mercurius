<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

/**
 * A provider over HTTP for loop tests: answers requests in turn from a queue; a body given as a generator is streamed
 * chunk by chunk, so it may pause or break mid-stream.
 *
 * ref: packages/llm/test/Support/FakeHttp.php — the same queue of canned responses, in the application's tests
 */
final class QueuedHttp implements DelegateHttpClient {
    /** @var list<string> request bodies, in order */
    public array $bodies = [];
    /** @var list<iterable<string>|string> */
    private array $queue = [];

    public function client(): HttpClient {
        return new HttpClient($this, []);
    }

    /** @param iterable<string>|string $body */
    public function stream(iterable|string $body): self {
        $this->queue[] = $body;
        return $this;
    }

    public function request(Request $request, Cancellation $cancellation): Response {
        $this->bodies[] = \Amp\ByteStream\buffer($request->getBody()->getContent());
        $body = array_shift($this->queue) ?? throw new \LogicException('No response queued');
        return new Response('1.1', 200, null, ['content-type' => 'text/event-stream'], is_string($body) ? $body : new ReadableIterableStream($body), $request);
    }

    /** @param array<string, mixed> $data */
    public static function event(array $data): string {
        return 'data: ' . json_encode($data, JSON_THROW_ON_ERROR) . "\n\n";
    }

    public static function text(string $text): string {
        return self::event(['id' => 'g', 'model' => 'a/m', 'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]])
            . "data: [DONE]\n\n";
    }

    public static function toolCall(string $id, string $name, string $args): string {
        $call = ['index' => 0, 'id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $args]];
        return self::event(['id' => 'g', 'model' => 'a/m', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [$call]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]])
            . "data: [DONE]\n\n";
    }
}
