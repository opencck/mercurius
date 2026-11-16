<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Entity\ChatTurn;
use OpenCCK\Domain\Repository\ConversationRepository;

final class InMemoryConversationRepository implements ConversationRepository {
    /** @var array<string, int> open conversation per channel:chat */
    private array $open = [];
    /** @var array<int, list<ChatTurn>> */
    public array $messages = [];
    private int $next = 1;

    public function openConversation(int $principalId, string $channel, string $chatId): int {
        return $this->open[$channel . ':' . $chatId] ??= $this->next++;
    }

    public function close(string $channel, string $chatId): void {
        unset($this->open[$channel . ':' . $chatId]);
    }

    public function append(int $conversationId, string $role, string $content, ?string $externalMessageId = null): void {
        $this->messages[$conversationId][] = new ChatTurn($role, $content);
    }

    public function recent(int $conversationId, int $limit): array {
        return array_slice($this->messages[$conversationId] ?? [], -$limit);
    }
}
