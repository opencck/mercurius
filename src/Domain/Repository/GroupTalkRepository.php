<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * What a group said without asking the agent, by chat and forum topic; the next run gets it as data.
 */
interface GroupTalkRepository {
    /** @param string $threadId the forum topic; '' — the chat itself */
    public function add(string $channel, string $chatId, string $threadId, string $line): void;

    /** @return list<string> the latest lines not older than $seconds, oldest first */
    public function recent(string $channel, string $chatId, string $threadId, int $limit, int $seconds): array;

    /** The bot left the chat: its talk is not kept. */
    public function forget(string $channel, string $chatId): void;
}
