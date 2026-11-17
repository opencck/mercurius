<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\Compaction;
use OpenCCK\Domain\Agent\ConversationMeta;

/**
 * Conversations of chats. Their messages live in TranscriptRepository.
 */
interface ConversationRepository {
    /**
     * Id of the open conversation of the chat (and forum topic); opens one when there is none.
     *
     * @param string $threadId the forum topic; '' — the chat itself
     */
    public function openConversation(int $principalId, string $channel, string $chatId, string $threadId = ''): int;

    /** Closes the open conversation of the chat (and forum topic), if any. */
    public function close(string $channel, string $chatId, string $threadId = ''): void;

    public function meta(int $conversationId): ?ConversationMeta;

    /** Fixes the system prompt and model of the conversation when it opens; they do not change afterwards. */
    public function setMeta(int $conversationId, string $systemPrompt, string $model, ?int $promptVersion = null): void;

    public function compaction(int $conversationId): Compaction;

    /** Saves a new summary covering messages up to $uptoId and clears failed attempts. */
    public function saveSummary(int $conversationId, string $summary, int $uptoId): void;

    /** Records a failed summary and the pause before the next attempt; returns failures in a row. */
    public function compactionFailed(int $conversationId, DateTimeImmutable $cooldownUntil): int;
}
