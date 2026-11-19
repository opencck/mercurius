<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\GroupIgnoreRepository;

final class PostgresGroupIgnoreRepository implements GroupIgnoreRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function add(string $channel, string $chatId, string $who, ?int $by): void {
        $this->db->execute('INSERT INTO group_ignores (channel, chat_id, who, added_by) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING', [$channel, $chatId, $who, $by]);
    }

    public function remove(string $channel, string $chatId, string $who): bool {
        return $this->db->execute('DELETE FROM group_ignores WHERE channel = ? AND chat_id = ? AND who = ? RETURNING who', [$channel, $chatId, $who])->fetchRow() !== null;
    }

    public function list(string $channel, string $chatId): array {
        $out = [];
        foreach ($this->db->execute('SELECT who FROM group_ignores WHERE channel = ? AND chat_id = ? ORDER BY who', [$channel, $chatId]) as $row) {
            $who = $row['who'] ?? null;
            if (is_string($who)) {
                $out[] = $who;
            }
        }
        return $out;
    }

    public function ignored(string $channel, string $chatId, string $userId, ?string $userName): bool {
        return $this->db->execute(
            'SELECT 1 FROM group_ignores WHERE channel = ? AND chat_id = ? AND (who = ? OR who = ?) LIMIT 1',
            [$channel, $chatId, $userId, mb_strtolower(ltrim((string) $userName, '@'))],
        )->fetchRow() !== null;
    }
}
