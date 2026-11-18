<?php

declare(strict_types=1);

namespace OpenCCK\App\Api;

use Closure;
use InvalidArgumentException;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\Websocket\Connection;
use Mercurius\Messenger\Websocket\WebsocketChannel;
use OpenCCK\Domain\Entity\Principal;

/**
 * The WebSocket API between a connection and the agent: a participant who came with a key gets their own private chat
 * (`ws:p<id>`) and an identity in the channel, so the agent sees the same participant with the same rights as in
 * Telegram; their frames go to the same queues as messages and presses of Telegram.
 *
 * spec: BRIEF.md#второй-канал-websocket-api
 */
final class ApiGateway {
    /**
     * @param Closure(IncomingMessage): mixed $messages
     * @param Closure(IncomingCallback): mixed $presses
     */
    public function __construct(
        private readonly WebsocketChannel $channel,
        public readonly ApiKeys $keys,
        private readonly Closure $messages,
        private readonly Closure $presses,
    ) {}

    public function open(Principal $principal, Connection $connection): ApiSession {
        $userId = 'p' . $principal->id;
        $this->keys->principals()->bindIdentity($principal->id, WebsocketChannel::NAME, $userId);
        $chatId = WebsocketChannel::PREFIX . $userId;
        return new ApiSession($principal, $chatId, $userId, $this->channel->attach($chatId, $connection), $connection);
    }

    /** One frame of the client; a frame that is not a message or a press gets an error frame back. */
    public function frame(ApiSession $session, string $frame): void {
        try {
            $incoming = $this->channel->receive($session->chatId, $session->userId, $session->principal->displayName, $frame);
        } catch (InvalidArgumentException $e) {
            $session->connection->send(json_encode(['type' => 'error', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return;
        }
        $incoming instanceof IncomingMessage ? ($this->messages)($incoming) : ($this->presses)($incoming);
    }

    public function close(ApiSession $session): void {
        $this->channel->detach($session->chatId, $session->key);
    }
}
