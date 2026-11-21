<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * A stream of a place given to an agent of the network: a chat, or one topic of it. The agent hears it only while
 * subscribed.
 *
 * spec: brief/network.md#потоки-мест-для-агентов
 */
final class PlaceStream {
    public function __construct(
        public readonly int $id,
        public readonly int $principalId,
        public readonly string $channel,
        public readonly string $chatId,
        /** '' — the whole chat, its topics too */
        public readonly string $threadId,
        public readonly string $title,
        public readonly bool $subscribed,
    ) {}

    /** Whether something said in this place falls into the stream. */
    public function covers(string $channel, string $chatId, string $threadId): bool {
        return $this->channel === $channel && $this->chatId === $chatId && ($this->threadId === '' || $this->threadId === $threadId);
    }

    /** @return array{stream: int, place: string, channel: string, chat: string, thread: string, subscribed: bool} */
    public function toArray(): array {
        return ['stream' => $this->id, 'place' => $this->title, 'channel' => $this->channel, 'chat' => $this->chatId, 'thread' => $this->threadId, 'subscribed' => $this->subscribed];
    }
}
