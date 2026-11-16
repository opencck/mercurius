<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Entity\ChatTurn;
use OpenCCK\Domain\Repository\ConversationRepository;

final class InMemoryConversationRepository implements ConversationRepository {
    /** @var array<string, int> open conversation per channel:chat */
    private array $open = [];
    /** @var array<int, list<ChatTurn>> */
    public array $messages = [];
    private int $next = 1;
    /** @var array<int, ConversationMeta> */
    public array $meta = [];

    public function openConversation(int $principalId, string $channel, string $chatId): int {
        $key = $channel . ':' . $chatId;
        if (!isset($this->open[$key])) {
            $this->open[$key] = $this->next++;
            $this->meta[$this->open[$key]] = new ConversationMeta($this->open[$key], $principalId, $channel, $chatId, null, null);
        }
        return $this->open[$key];
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

    public function meta(int $conversationId): ?ConversationMeta {
        return $this->meta[$conversationId] ?? null;
    }

    public function setMeta(int $conversationId, string $systemPrompt, string $model): void {
        $meta = $this->meta[$conversationId];
        if ($meta->systemPrompt === null) {
            $this->meta[$conversationId] = new ConversationMeta($meta->id, $meta->principalId, $meta->channel, $meta->chatId, $systemPrompt, $model);
        }
    }
}
