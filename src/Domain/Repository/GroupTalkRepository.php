<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * What a group said without asking the agent, by chat and forum topic; the next run gets it as data.
 */
interface GroupTalkRepository {
    /**
     * @param string $threadId the forum topic; '' — the chat itself
     * @param string $userId who wrote it, as the channel names them; '' — not known
     */
    public function add(string $channel, string $chatId, string $threadId, string $line, string $userId = ''): void;

    /** @return list<string> who wrote in the chat or topic in the last $seconds, as the channel names them */
    public function speakers(string $channel, string $chatId, string $threadId, int $seconds): array;

    /** @return list<string> the latest lines not older than $seconds, oldest first */
    public function recent(string $channel, string $chatId, string $threadId, int $limit, int $seconds): array;

    /** The bot left the chat: its talk is not kept. */
    public function forget(string $channel, string $chatId): void;
}
