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

    public function openConversation(int $principalId, string $channel, string $chatId, string $threadId = ''): int {
        $key = $channel . ':' . $chatId . ':' . $threadId;
        if (!isset($this->open[$key])) {
            $this->open[$key] = $this->next++;
            $this->meta[$this->open[$key]] = new ConversationMeta($this->open[$key], $principalId, $channel, $chatId, null, null, $threadId);
        }
        return $this->open[$key];
    }

    public function close(string $channel, string $chatId, string $threadId = ''): void {
        unset($this->open[$channel . ':' . $chatId . ':' . $threadId]);
    }

    public function isOpen(int $conversationId): bool {
        return in_array($conversationId, $this->open, true);
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

    /** @var array<int, int|null> */
    public array $promptVersions = [];

    public function openModels(): array {
        $models = [];
        foreach ($this->open as $id) {
            $model = $this->meta[$id]->model ?? null;
            if ($model !== null) {
                $models[$id] = $model;
            }
        }
        return $models;
    }

    public function switchModel(int $conversationId, string $model): void {
        $meta = $this->meta[$conversationId] ?? null;
        if ($meta !== null && in_array($conversationId, $this->open, true)) {
            $this->meta[$conversationId] = new ConversationMeta($meta->id, $meta->principalId, $meta->channel, $meta->chatId, $meta->systemPrompt, $model, $meta->threadId);
        }
    }

    public function setMeta(int $conversationId, string $systemPrompt, string $model, ?int $promptVersion = null): void {
        $this->promptVersions[$conversationId] ??= $promptVersion;
        $meta = $this->meta[$conversationId];
        if ($meta->systemPrompt === null) {
            $this->meta[$conversationId] = new ConversationMeta($meta->id, $meta->principalId, $meta->channel, $meta->chatId, $systemPrompt, $model, $meta->threadId);
        }
    }
}
