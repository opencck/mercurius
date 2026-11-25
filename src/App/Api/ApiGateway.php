<?php

declare(strict_types=1);

namespace OpenCCK\App\Api;

use Closure;
use InvalidArgumentException;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\Websocket\Connection;
use Mercurius\Messenger\Websocket\WebsocketChannel;
use OpenCCK\Domain\Entity\Member;

/**
 * The WebSocket API between a connection and the agent: a participant who came with a key gets their own private chat
 * (`ws:p<id>`) and an identity in the channel, so the agent sees the same participant with the same rights as in
 * Telegram; their frames go to the same queues as messages and presses of Telegram.
 *
 * spec: brief/operations.md#второй-канал-websocket-api
 */
final class ApiGateway {
    /**
     * @param Closure(IncomingMessage): mixed $messages
     * @param Closure(IncomingCallback): mixed $presses
     */
    /** @var array<int, ApiSession> open connections by their number */
    private array $sessions = [];
    private int $next = 1;

    public function __construct(
        private readonly WebsocketChannel $channel,
        public readonly ApiKeys $keys,
        private readonly Closure $messages,
        private readonly Closure $presses,
    ) {
        $keys->onRevoke($this->closeKey(...));
    }

    /**
     * @param int $apiKey the number of the key the connection came with: a revoke of that key closes it
     * @param (Closure(): void)|null $close closes the connection
     */
    public function open(Member $principal, Connection $connection, int $apiKey = 0, ?Closure $close = null): ApiSession {
        $userId = 'p' . $principal->id;
        $this->keys->principals()->bindIdentity($principal->id, WebsocketChannel::NAME, $userId);
        $chatId = WebsocketChannel::PREFIX . $userId;
        $session = new ApiSession($principal, $chatId, $userId, $this->channel->attach($chatId, $connection), $connection, $apiKey, $close);
        $this->sessions[$this->next++] = $session;
        return $session;
    }

    /** One frame of the client; a frame that is not a message or a press gets an error frame back. */
    public function frame(ApiSession $session, string $frame): void {
        // spec: brief/operations.md#второй-канал-websocket-api — the key and the participant are checked on every frame:
        //       a revoked key or a participant who is no longer active is not heard, the connection closes
        if ($session->apiKey !== 0 && !$this->keys->alive($session->apiKey, $session->principal->id)) {
            $this->end($session);
            return;
        }
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
        foreach ($this->sessions as $id => $open) {
            if ($open === $session) {
                unset($this->sessions[$id]);
            }
        }
    }

    /** Closes the connections that came with this key. */
    private function closeKey(int $apiKey): void {
        foreach ($this->sessions as $session) {
            if ($session->apiKey === $apiKey) {
                $this->end($session);
            }
        }
    }

    private function end(ApiSession $session): void {
        if (!in_array($session, $this->sessions, true)) {
            return;
        }
        $this->close($session);
        if ($session->close !== null) {
            ($session->close)();
        }
    }
}
