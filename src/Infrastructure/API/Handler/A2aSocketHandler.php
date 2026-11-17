<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\Infrastructure\Peer\WebsocketA2aChannel;

/**
 * GET /a2a/ws — neighbouring agents by WebSocket. The entrance token is checked before the upgrade (401 otherwise);
 * on the connection the agent proves its key and then sends JSON-RPC of A2A.
 *
 * spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
 */
final class A2aSocketHandler implements WebsocketAcceptor, WebsocketClientHandler {
    public function __construct(
        private readonly A2aServer $server,
        private readonly Rfc6455Acceptor $upgrade = new Rfc6455Acceptor(),
    ) {}

    public function handleHandshake(Request $request): Response {
        if (!$this->server->entrance($request->getHeader('authorization') ?? '')) {
            return new Response(401, ['content-type' => 'application/json'], '{"error":"unauthorized"}');
        }
        return $this->upgrade->handleHandshake($request);
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void {
        $this->server->serveSocket(new WebsocketA2aChannel($client));
    }
}
