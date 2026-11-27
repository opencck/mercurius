<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\Compaction;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Repository\ConversationRepository;
use UnexpectedValueException;

final class PostgresConversationRepository implements ConversationRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function openConversation(int $principalId, string $channel, string $chatId, string $threadId = ''): int {
        $row = $this->db->execute(
            'WITH inserted AS (
                INSERT INTO conversations (principal_id, channel, chat_id, thread_id) VALUES (?, ?, ?, ?)
                ON CONFLICT (channel, chat_id, thread_id) WHERE closed_at IS NULL DO NOTHING
                RETURNING id
             )
             SELECT id FROM inserted
             UNION ALL
             SELECT id FROM conversations WHERE channel = ? AND chat_id = ? AND thread_id = ? AND closed_at IS NULL
             LIMIT 1',
            [$principalId, $channel, $chatId, $threadId, $channel, $chatId, $threadId],
        )->fetchRow();

        $id = $row['id'] ?? null;
        if (!is_int($id)) {
            throw new UnexpectedValueException('Could not open a conversation');
        }
        return $id;
    }

    public function close(string $channel, string $chatId, string $threadId = ''): void {
        $this->db->execute(
            'UPDATE conversations SET closed_at = now() WHERE channel = ? AND chat_id = ? AND thread_id = ? AND closed_at IS NULL',
            [$channel, $chatId, $threadId],
        );
    }

    public function setContext(int $conversationId, ?string $context): void {
        $this->db->execute('UPDATE conversations SET context = ? WHERE id = ?', [$context, $conversationId]);
    }

    public function setPrivateChat(int $conversationId, bool $private): void {
        $this->db->execute('UPDATE conversations SET private_chat = ? WHERE id = ? AND private_chat IS DISTINCT FROM ?', [$private, $conversationId, $private]);
    }

    public function privateChat(int $conversationId): ?bool {
        $row = $this->db->execute('SELECT private_chat FROM conversations WHERE id = ?', [$conversationId])->fetchRow();
        $value = $row['private_chat'] ?? null;
        return is_bool($value) ? $value : null;
    }

    public function isOpen(int $conversationId): bool {
        foreach ($this->db->execute('SELECT 1 FROM conversations WHERE id = ? AND closed_at IS NULL', [$conversationId]) as $row) {
            return true;
        }
        return false;
    }

    public function meta(int $conversationId): ?ConversationMeta {
        $row = $this->db->execute(
            'SELECT id, principal_id, channel, chat_id, system_prompt, model, thread_id, context FROM conversations WHERE id = ?',
            [$conversationId],
        )->fetchRow();
        if ($row === null) {
            return null;
        }
        $id = $row['id'] ?? null;
        $principalId = $row['principal_id'] ?? null;
        $channel = $row['channel'] ?? null;
        $chatId = $row['chat_id'] ?? null;
        $prompt = $row['system_prompt'] ?? null;
        $model = $row['model'] ?? null;
        if (!is_int($id) || !is_int($principalId) || !is_string($channel) || !is_string($chatId)
            || ($prompt !== null && !is_string($prompt)) || ($model !== null && !is_string($model))) {
            throw new UnexpectedValueException('Malformed conversations row');
        }
        $thread = $row['thread_id'] ?? '';
        $context = $row['context'] ?? null;
        return new ConversationMeta($id, $principalId, $channel, $chatId, $prompt, $model, is_string($thread) ? $thread : '', is_string($context) ? $context : null);
    }

    public function compaction(int $conversationId): Compaction {
        $row = $this->db->execute(
            'SELECT summary, summary_upto, compaction_failures, extract(epoch FROM compaction_cooldown_until)::bigint AS cooldown
             FROM conversations WHERE id = ?',
            [$conversationId],
        )->fetchRow() ?? [];
        $summary = $row['summary'] ?? null;
        $upto = $row['summary_upto'] ?? 0;
        $failures = $row['compaction_failures'] ?? 0;
        $cooldown = $row['cooldown'] ?? null;
        return new Compaction(
            is_string($summary) ? $summary : null,
            is_int($upto) ? $upto : 0,
            is_int($failures) ? $failures : 0,
            is_int($cooldown) ? (new DateTimeImmutable())->setTimestamp($cooldown) : null,
        );
    }

    public function saveSummary(int $conversationId, string $summary, int $uptoId): void {
        $this->db->execute(
            'UPDATE conversations SET summary = ?, summary_upto = ?, compaction_failures = 0, compaction_cooldown_until = NULL WHERE id = ?',
            [$summary, $uptoId, $conversationId],
        );
    }

    public function compactionFailed(int $conversationId, DateTimeImmutable $cooldownUntil): int {
        $row = $this->db->execute(
            'UPDATE conversations SET compaction_failures = compaction_failures + 1, compaction_cooldown_until = to_timestamp(?)
             WHERE id = ? RETURNING compaction_failures',
            [$cooldownUntil->getTimestamp(), $conversationId],
        )->fetchRow();
        $failures = $row['compaction_failures'] ?? 0;
        return is_int($failures) ? $failures : 0;
    }

    public function openModels(): array {
        $models = [];
        foreach ($this->db->query('SELECT id, model FROM conversations WHERE closed_at IS NULL AND model IS NOT NULL') as $row) {
            $id = $row['id'] ?? null;
            $model = $row['model'] ?? null;
            if (is_int($id) && is_string($model)) {
                $models[$id] = $model;
            }
        }
        return $models;
    }

    public function switchModel(int $conversationId, string $model): void {
        $this->db->execute('UPDATE conversations SET model = ? WHERE id = ? AND closed_at IS NULL', [$model, $conversationId]);
    }

    public function setMeta(int $conversationId, string $systemPrompt, string $model, ?int $promptVersion = null): void {
        $this->db->execute(
            'UPDATE conversations SET system_prompt = ?, model = ?, prompt_version = ? WHERE id = ? AND system_prompt IS NULL',
            [$systemPrompt, $model, $promptVersion, $conversationId],
        );
    }
}
