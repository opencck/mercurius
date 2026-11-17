<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Which run wrote a message the agent delivered to a chat. A reply to the agent's message names its run, so the agent
 * looks up the right spend instead of guessing.
 */
interface RunMessageRepository {
    public function record(int $runId, string $channel, string $chatId, string $messageId): void;

    public function runOf(string $channel, string $chatId, string $messageId): ?int;
}
