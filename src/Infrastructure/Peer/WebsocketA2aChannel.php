<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Peer;

use Amp\Cancellation;
use Amp\Websocket\WebsocketClient;
use Mercurius\Peer\A2aChannel;
use Throwable;

/**
 * A WebSocket connection as a channel of text frames, on either side (amphp's server client and client connection
 * share the interface). A frame bigger than the limit closes the connection: an agent does not need it.
 *
 * spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
 */
final class WebsocketA2aChannel implements A2aChannel {
    public const FRAME_MAX = 262_144;

    /** bytes in a message after the handshake, when files go as bytes (A2A_FILE_TRANSPORT=ws): base64 and JSON of 20 MB */
    public const FILES_MAX = 28_000_000;
    /** the handshake takes two frames each way: until then a frame is small whoever the other side is */
    private const HANDSHAKE_FRAMES = 2;
    private int $received = 0;

    public function __construct(
        private readonly WebsocketClient $socket,
        /** the largest frame after the handshake */
        private readonly int $after = self::FRAME_MAX,
    ) {}

    public function send(string $text): void {
        $this->socket->sendText($text);
    }

    public function receive(?Cancellation $cancellation = null): ?string {
        try {
            $message = $this->socket->receive($cancellation);
            // spec: BRIEF.md#между-агентами — a large frame only after the keys are proved
            $limit = $this->received++ < self::HANDSHAKE_FRAMES ? self::FRAME_MAX : $this->after;
            return $message?->buffer($cancellation, $limit);
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
