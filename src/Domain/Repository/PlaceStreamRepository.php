<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Peer\PlaceStream;

/**
 * The streams of places the owner of the agent gave to agents of the network, and which of them they listen to.
 *
 * spec: brief/network.md#потоки-мест-для-агентов
 */
interface PlaceStreamRepository {
    /** Gives a stream; giving it again keeps one. */
    public function grant(int $principalId, string $channel, string $chatId, string $threadId, string $title, ?int $by): PlaceStream;

    /** Takes a stream back and ends its subscription; null — no such stream. */
    public function revoke(int $id): ?PlaceStream;

    public function find(int $id): ?PlaceStream;

    /** @return list<PlaceStream> the streams of an agent, or of every agent */
    public function of(?int $principalId): array;

    public function setSubscribed(int $id, bool $subscribed): void;

    /** @return list<PlaceStream> the subscribed streams of the chat */
    public function listeners(string $channel, string $chatId): array;
}
