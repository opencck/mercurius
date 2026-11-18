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
        if ($request->getMethod() === 'GET' && !str_starts_with($request->getUri()->getPath(), '/a2a/files')) {
            parse_str($request->getUri()->getQuery(), $query);
            $nonce = isset($query['nonce']) && is_string($query['nonce']) ? $query['nonce'] : null;
            return new Response('1.1', 200, null, [], json_encode($this->server->card($nonce), JSON_THROW_ON_ERROR), $request);
        }
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/a2a/files')) {
            return $this->files($request, $path);
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

    /** POST /a2a/files and GET /a2a/files/<number>, as the files handler passes them. */
    private function files(Request $request, string $path): Response {
        $body = '';
        $stream = $request->getBody()->getContent();
        while (($chunk = $stream->read()) !== null) {
            $body .= $chunk;
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        [$agent, $status, $why] = $this->server->authenticate($headers, $request->getMethod(), $path, $body);
        if ($agent === null) {
            return new Response('1.1', $status, null, [], json_encode(['error' => $why], JSON_THROW_ON_ERROR), $request);
        }
        $nonce = $headers['x-a2a-nonce'] ?? '';
        if ($request->getMethod() === 'POST') {
            $json = json_encode($this->server->upload($agent, $headers['content-type'] ?? '', $body), JSON_THROW_ON_ERROR);
            return new Response('1.1', 200, null, $this->server->sign($nonce, $json), $json, $request);
        }
        $file = $this->server->download($agent, substr($path, strlen('/a2a/files/')));
        return $file === null
            ? new Response('1.1', 404, null, $this->server->sign($nonce, '{}'), '{}', $request)
            : new Response('1.1', 200, null, $this->server->sign($nonce, $file['bytes']), $file['bytes'], $request);
    }
}
