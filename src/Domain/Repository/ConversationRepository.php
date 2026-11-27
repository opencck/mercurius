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

    /** spec: brief/participants.md#контекст-беседы-в-личном-чате — null — the member's own, 'owners' or 'project:<id>' */
    public function setContext(int $conversationId, ?string $context): void;

    /** Keeps whether the conversation is a private chat. */
    public function setPrivateChat(int $conversationId, bool $private): void;

    /** Whether the conversation is a private chat; null — not known yet. */
    public function privateChat(int $conversationId): ?bool;

    /** Whether the conversation is still the open one of its chat (not closed by /new). */
    public function isOpen(int $conversationId): bool;

    /** Fixes the system prompt and model of the conversation when it opens; they do not change afterwards. */
    public function setMeta(int $conversationId, string $systemPrompt, string $model, ?int $promptVersion = null): void;

    /** @return array<int, string> the open conversations that have a model => the model */
    public function openModels(): array;

    /** An open conversation goes on with another model from its next run. */
    public function switchModel(int $conversationId, string $model): void;

    public function compaction(int $conversationId): Compaction;

    /** Saves a new summary covering messages up to $uptoId and clears failed attempts. */
    public function saveSummary(int $conversationId, string $summary, int $uptoId): void;

    /** Records a failed summary and the pause before the next attempt; returns failures in a row. */
    public function compactionFailed(int $conversationId, DateTimeImmutable $cooldownUntil): int;
}
