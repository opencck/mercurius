<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\GroupTalkRepository;

final class PostgresGroupTalkRepository implements GroupTalkRepository {
    /** Lines older than this are deleted when new ones come. */
    private const KEEP_DAYS = 7;

    public function __construct(private readonly PostgresLink $db) {}

    public function add(string $channel, string $chatId, string $threadId, string $line): void {
        $this->db->execute('INSERT INTO group_talk (channel, chat_id, thread_id, line) VALUES (?, ?, ?, ?)', [$channel, $chatId, $threadId, $line]);
        $this->db->execute(
            "DELETE FROM group_talk WHERE channel = ? AND chat_id = ? AND created_at < now() - make_interval(days => ?)",
            [$channel, $chatId, self::KEEP_DAYS],
        );
    }

    public function recent(string $channel, string $chatId, string $threadId, int $limit, int $seconds): array {
        $lines = [];
        foreach ($this->db->execute(
            'SELECT line FROM (
                SELECT id, line FROM group_talk
                WHERE channel = ? AND chat_id = ? AND thread_id = ? AND created_at >= now() - make_interval(secs => ?)
                ORDER BY id DESC LIMIT ?
             ) t ORDER BY id',
            [$channel, $chatId, $threadId, $seconds, $limit],
        ) as $row) {
            $line = $row['line'] ?? null;
            if (is_string($line)) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    public function forget(string $channel, string $chatId): void {
        $this->db->execute('DELETE FROM group_talk WHERE channel = ? AND chat_id = ?', [$channel, $chatId]);
    }
}
