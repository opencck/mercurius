<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Peer;

use Amp\Cancellation;
use Amp\Websocket\WebsocketClient;
use OpenCCK\App\Peer\A2aChannel;
use Throwable;

/**
 * A WebSocket connection as a channel of text frames, on either side (amphp's server client and client connection
 * share the interface). A frame bigger than the limit closes the connection: an agent does not need it.
 *
 * spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
 */
final class WebsocketA2aChannel implements A2aChannel {
    public const FRAME_MAX = 262_144;

    public function __construct(private readonly WebsocketClient $socket) {}

    public function send(string $text): void {
        $this->socket->sendText($text);
    }

    public function receive(?Cancellation $cancellation = null): ?string {
        try {
            $message = $this->socket->receive($cancellation);
            return $message?->buffer($cancellation, self::FRAME_MAX);
        } catch (\Amp\CancelledException $e) {
            throw $e;
        } catch (Throwable) {
            $this->close();
            return null;
        }
    }

    public function close(): void {
        if (!$this->socket->isClosed()) {
            $this->socket->close();
        }
    }

    public function isClosed(): bool {
        return $this->socket->isClosed();
    }
}
