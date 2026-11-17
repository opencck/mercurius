<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

/**
 * Answers by URL from a table; unknown URLs get 404.
 */
final class FakeHttpClient implements DelegateHttpClient {
    /** @var list<string> "METHOD url" of every request */
    public array $requested = [];
    /** @var list<array<string, string>> headers of every request, names in lower case */
    public array $headers = [];
    /** @var list<string> body of every request */
    public array $bodies = [];

    /** @param array<string, array{int, array<non-empty-string, string>, string}> $responses url => [status, headers, body] */
    public function __construct(private readonly array $responses = []) {}

    public function request(Request $request, Cancellation $cancellation): Response {
        $url = (string) $request->getUri();
        $this->requested[] = $request->getMethod() . ' ' . $url;
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        $this->headers[] = $headers;
        $this->bodies[] = \Amp\ByteStream\buffer($request->getBody()->getContent());
        [$status, $headers, $body] = $this->responses[$url] ?? [404, [], 'not found'];
        return new Response('1.1', $status, null, $headers, $body, $request);
    }
}
