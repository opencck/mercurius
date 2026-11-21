<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Peer\PlaceStream;
use OpenCCK\Domain\Repository\PlaceStreamRepository;

final class InMemoryPlaceStreamRepository implements PlaceStreamRepository {
    /** @var array<int, PlaceStream> */
    public array $streams = [];

    public function grant(int $principalId, string $channel, string $chatId, string $threadId, string $title, ?int $by): PlaceStream {
        foreach ($this->streams as $stream) {
            if ($stream->principalId === $principalId && $stream->channel === $channel && $stream->chatId === $chatId && $stream->threadId === $threadId) {
                return $stream;
            }
        }
        $id = $this->streams === [] ? 1 : max(array_keys($this->streams)) + 1;
        return $this->streams[$id] = new PlaceStream($id, $principalId, $channel, $chatId, $threadId, $title, false);
    }

    public function revoke(int $id): ?PlaceStream {
        $stream = $this->streams[$id] ?? null;
        unset($this->streams[$id]);
        return $stream;
    }

    public function find(int $id): ?PlaceStream {
        return $this->streams[$id] ?? null;
    }

    public function of(?int $principalId): array {
        return array_values(array_filter($this->streams, static fn(PlaceStream $s): bool => $principalId === null || $s->principalId === $principalId));
    }

    public function setSubscribed(int $id, bool $subscribed): void {
        $s = $this->streams[$id] ?? null;
        if ($s !== null) {
            $this->streams[$id] = new PlaceStream($s->id, $s->principalId, $s->channel, $s->chatId, $s->threadId, $s->title, $subscribed);
        }
    }

    public function listeners(string $channel, string $chatId): array {
        return array_values(array_filter($this->streams, static fn(PlaceStream $s): bool => $s->subscribed && $s->channel === $channel && $s->chatId === $chatId));
    }
}
