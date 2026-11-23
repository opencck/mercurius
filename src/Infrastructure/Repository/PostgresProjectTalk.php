<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;

/**
 * The talk of the last day in the places of a project (its chats and forum topics): what sleep of the project reads.
 *
 * spec: brief/memory.md#сон-проекта
 */
final class PostgresProjectTalk {
    /** messages read at most: the newest of a day */
    private const MESSAGES = 200;

    public function __construct(private readonly PostgresLink $db) {}

    /** The messages of the people and the agent, oldest first, within $chars from the newest; '' — no talk today. */
    public function ofDay(int $projectId, int $chars): string {
        $lines = [];
        $left = $chars;
        $rows = $this->db->execute(
            "SELECT m.role, m.content FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             JOIN chat_projects p ON p.channel = c.channel AND p.chat_id = c.chat_id AND p.thread_id = c.thread_id
             WHERE p.project_id = ? AND m.role IN ('user', 'assistant') AND m.content <> '' AND m.created_at > now() - interval '1 day'
             ORDER BY m.id DESC LIMIT ?",
            [$projectId, self::MESSAGES],
        );
        foreach ($rows as $row) {
            $role = isset($row['role']) && is_string($row['role']) ? $row['role'] : '';
            $content = isset($row['content']) && is_string($row['content']) ? trim($row['content']) : '';
            $line = $role . ': ' . str_replace("\n", ' ', $content);
            if (mb_strlen($line) + 1 > $left) {
                break;
            }
            $left -= mb_strlen($line) + 1;
            $lines[] = $line;
        }
        return implode("\n", array_reverse($lines));
    }
}
