<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Bots and visitors the agent does not hear in a group, named by its participants: a user id or a name without @, in
 * lower case.
 *
 * spec: BRIEF.md#группа
 */
interface GroupIgnoreRepository {
    public function add(string $channel, string $chatId, string $who, ?int $by): void;

    public function remove(string $channel, string $chatId, string $who): bool;

    /** @return list<string> */
    public function list(string $channel, string $chatId): array;

    /** Whether a message of this user (by id or by name) is not heard in the chat. */
    public function ignored(string $channel, string $chatId, string $userId, ?string $userName): bool;
}
