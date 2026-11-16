<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\ConversationMeta;

use OpenCCK\Domain\Entity\ChatTurn;

interface ConversationRepository {
    /** Id of the open conversation of the chat; opens one when there is none. */
    public function openConversation(int $principalId, string $channel, string $chatId): int;

    /** Closes the open conversation of the chat, if any. */
    public function close(string $channel, string $chatId): void;

    /** @param 'user'|'assistant' $role */
    public function append(int $conversationId, string $role, string $content, ?string $externalMessageId = null): void;

    /**
     * Last messages in chronological order.
     *
     * @param positive-int $limit
     * @return list<ChatTurn>
     */
    public function recent(int $conversationId, int $limit): array;

    public function meta(int $conversationId): ?ConversationMeta;

    /** Fixes the system prompt and model of the conversation when it opens; they do not change afterwards. */
    public function setMeta(int $conversationId, string $systemPrompt, string $model): void;
}
