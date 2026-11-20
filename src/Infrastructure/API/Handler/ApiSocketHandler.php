<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Mercurius\Messenger\Websocket\Connection;
use OpenCCK\App\Api\ApiGateway;
use Throwable;

/**
 * GET /api/ws — the WebSocket API of participants. The key is checked before the upgrade (401 otherwise); then every
 * text frame is a message or a press of a button, and the agent's messages come back as frames.
 *
 * spec: brief/operations.md#второй-канал-websocket-api
 */
final class ApiSocketHandler implements WebsocketAcceptor, WebsocketClientHandler {
    public function __construct(
        private readonly ApiGateway $gateway,
        private readonly Rfc6455Acceptor $upgrade = new Rfc6455Acceptor(),
    ) {}

    public function handleHandshake(Request $request): Response {
        if ($this->gateway->keys->authenticate($request->getHeader('authorization') ?? '') === null) {
            return new Response(401, ['content-type' => 'application/json'], '{"error":"unauthorized"}');
        }
        return $this->upgrade->handleHandshake($request);
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void {
        // the key is checked again: it may have been revoked between the handshake and now
        $found = $this->gateway->keys->session($request->getHeader('authorization') ?? '');
        if ($found === null) {
            $client->close();
            return;
        }
        [$principal, $apiKey] = $found;
        $session = $this->gateway->open($principal, new class ($client) implements Connection {
            public function __construct(private readonly WebsocketClient $client) {}

            public function send(string $frame): bool {
                try {
                    $this->client->sendText($frame);
                    return true;
                } catch (Throwable) {
                    return false;
                }
            }
        }, $apiKey, static function () use ($client): void {
            $client->close();
        });
        try {
            while (($message = $client->receive()) !== null) {
                $this->gateway->frame($session, $message->buffer(limit: 30_000_000));
            }
        } finally {
            $this->gateway->close($session);
        }
    }
}
