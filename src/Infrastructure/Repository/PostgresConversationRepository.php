<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
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
}
