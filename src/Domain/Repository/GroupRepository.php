<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Group\Group;

/**
 * spec: brief/channels.md#как-сделано-группы
 */
interface GroupRepository {
    public function find(string $channel, string $chatId): ?Group;

    /** A new pending group; false when it is known already (the card goes once). */
    public function create(string $channel, string $chatId, string $title): bool;

    public function decide(string $channel, string $chatId, string $status, string $mode): ?Group;

    /** @return list<Group> */
    public function all(): array;

    /** Records whether the group is a forum (Telegram says so with every message). */
    public function setForum(string $channel, string $chatId, bool $forum): void;

    /** @param ?int $tokens tokens a day of the free mode; null — the default, 0 — no limit */
    public function setDailyTokens(string $channel, string $chatId, ?int $tokens): ?Group;
}
