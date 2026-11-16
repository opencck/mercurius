<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\Compaction;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Repository\ConversationRepository;

final class InMemoryConversationRepository implements ConversationRepository {
    /** @var array<string, int> open conversation per channel:chat */
    private array $open = [];
    private int $next = 1;
    /** @var array<int, ConversationMeta> */
    public array $meta = [];
    /** @var array<int, Compaction> */
    public array $compactions = [];

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

    public function meta(int $conversationId): ?ConversationMeta {
        return $this->meta[$conversationId] ?? null;
    }

    public function compaction(int $conversationId): Compaction {
        return $this->compactions[$conversationId] ?? new Compaction(null, 0, 0, null);
    }

    public function saveSummary(int $conversationId, string $summary, int $uptoId): void {
        $this->compactions[$conversationId] = new Compaction($summary, $uptoId, 0, null);
    }

    public function compactionFailed(int $conversationId, DateTimeImmutable $cooldownUntil): int {
        $c = $this->compaction($conversationId);
        $this->compactions[$conversationId] = new Compaction($c->summary, $c->uptoId, $c->failures + 1, $cooldownUntil);
        return $c->failures + 1;
    }

    public function setMeta(int $conversationId, string $systemPrompt, string $model): void {
        $meta = $this->meta[$conversationId];
        if ($meta->systemPrompt === null) {
            $this->meta[$conversationId] = new ConversationMeta($meta->id, $meta->principalId, $meta->channel, $meta->chatId, $systemPrompt, $model);
        }
    }
}
