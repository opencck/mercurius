<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Group\Group;

/**
 * spec: BRIEF.md#telegram-группы-каналы-и-темы
 */
interface GroupRepository {
    public function find(string $channel, string $chatId): ?Group;

    /** A new pending group; false when it is known already (the card goes once). */
    public function create(string $channel, string $chatId, string $title): bool;

    public function decide(string $channel, string $chatId, string $status, string $mode): ?Group;

    /** @return list<Group> */
    public function all(): array;
}
