<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\ConversationMeta;

/**
 * Conversations of chats. Their messages live in TranscriptRepository.
 */
interface ConversationRepository {
    /** Id of the open conversation of the chat; opens one when there is none. */
    public function openConversation(int $principalId, string $channel, string $chatId): int;

    /** Closes the open conversation of the chat, if any. */
    public function close(string $channel, string $chatId): void;

    public function meta(int $conversationId): ?ConversationMeta;

    /** Fixes the system prompt and model of the conversation when it opens; they do not change afterwards. */
    public function setMeta(int $conversationId, string $systemPrompt, string $model): void;
}
