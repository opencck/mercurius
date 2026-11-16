<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;


use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Entity\ChatTurn;
use OpenCCK\Domain\Repository\ConversationRepository;
use UnexpectedValueException;

final class PostgresConversationRepository implements ConversationRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function openConversation(int $principalId, string $channel, string $chatId): int {
        $row = $this->db->execute(
            'WITH inserted AS (
                INSERT INTO conversations (principal_id, channel, chat_id) VALUES (?, ?, ?)
                ON CONFLICT (channel, chat_id) WHERE closed_at IS NULL DO NOTHING
                RETURNING id
             )
             SELECT id FROM inserted
             UNION ALL
             SELECT id FROM conversations WHERE channel = ? AND chat_id = ? AND closed_at IS NULL
             LIMIT 1',
            [$principalId, $channel, $chatId, $channel, $chatId],
        )->fetchRow();

        $id = $row['id'] ?? null;
        if (!is_int($id)) {
            throw new UnexpectedValueException('Could not open a conversation');
        }
        return $id;
    }

    public function close(string $channel, string $chatId): void {
        $this->db->execute(
            'UPDATE conversations SET closed_at = now() WHERE channel = ? AND chat_id = ? AND closed_at IS NULL',
            [$channel, $chatId],
        );
    }

    public function append(int $conversationId, string $role, string $content, ?string $externalMessageId = null): void {
        $this->db->execute(
            'INSERT INTO messages (conversation_id, role, content, external_message_id) VALUES (?, ?, ?, ?)',
            [$conversationId, $role, $content, $externalMessageId],
        );
    }

    public function recent(int $conversationId, int $limit): array {
        $result = $this->db->execute(
            'SELECT role, content FROM (
                SELECT id, role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?
             ) last ORDER BY id',
            [$conversationId, $limit],
        );

        $turns = [];
        foreach ($result as $row) {
            $role = $row['role'] ?? null;
            $content = $row['content'] ?? null;
            if (($role !== 'user' && $role !== 'assistant') || !is_string($content)) {
                throw new UnexpectedValueException('Malformed messages row');
            }
            $turns[] = new ChatTurn($role, $content);
        }
        return $turns;
    }

    public function meta(int $conversationId): ?ConversationMeta {
        $row = $this->db->execute(
            'SELECT id, principal_id, channel, chat_id, system_prompt, model FROM conversations WHERE id = ?',
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
        return new ConversationMeta($id, $principalId, $channel, $chatId, $prompt, $model);
    }

    public function setMeta(int $conversationId, string $systemPrompt, string $model): void {
        $this->db->execute(
            'UPDATE conversations SET system_prompt = ?, model = ? WHERE id = ? AND system_prompt IS NULL',
            [$systemPrompt, $model, $conversationId],
        );
    }
}
