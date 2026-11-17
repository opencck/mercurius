<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpenCCK\App\Peer\A2aServer;

/**
 * HTTP to an A2A server goes straight into it, the way the A2A handler passes it: authentication, the call, a signed
 * answer.
 */
final class A2aWire implements DelegateHttpClient {
    public function __construct(private readonly A2aServer $server) {}

    public function request(Request $request, Cancellation $cancellation): Response {
        if ($request->getMethod() === 'GET') {
            return new Response('1.1', 200, null, [], json_encode($this->server->card(), JSON_THROW_ON_ERROR), $request);
        }
        if ($this->server->transport === 'ws') {
            // the follower takes WebSocket only: POST /a2a is not there
            return new Response('1.1', 404, null, [], '{"error":"not found"}', $request);
        }
        $body = '';
        $stream = $request->getBody()->getContent();
        while (($chunk = $stream->read()) !== null) {
            $body .= $chunk;
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        [$agent, $status, $why] = $this->server->authenticate($headers, 'POST', '/a2a', $body);
        if ($agent === null) {
            return new Response('1.1', $status, null, [], json_encode(A2aServer::error(null, A2aServer::ERR_UNAUTHORIZED, $why), JSON_THROW_ON_ERROR), $request);
        }
        /** @var mixed $data */
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        [$code, $answer] = $this->server->handle($agent, is_array($data) ? $data : []);
        $json = json_encode($answer, JSON_THROW_ON_ERROR);
        return new Response('1.1', $code, null, $this->server->sign($headers['x-a2a-nonce'] ?? '', $json), $json, $request);
    }
}
