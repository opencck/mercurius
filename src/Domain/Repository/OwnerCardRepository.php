<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The copies of a card sent to every owner: a decision on one copy edits all of them.
 *
 * spec: brief/participants.md#свои-роли — cards and notices go to every owner
 */
interface OwnerCardRepository {
    /**
     * @param non-empty-list<array{string, string}> $copies chat, message
     * @return positive-int the id of the card
     */
    public function create(array $copies): int;

    /** @return list<array{string, string}> chat, message */
    public function copies(int $card): array;

    /** The card a delivered message is a copy of; null for any other message. */
    public function cardOf(string $chatId, string $messageId): ?int;
}
