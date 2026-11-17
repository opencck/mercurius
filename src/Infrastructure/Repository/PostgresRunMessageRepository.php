<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\RunMessageRepository;

final class PostgresRunMessageRepository implements RunMessageRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function record(int $runId, string $channel, string $chatId, string $messageId): void {
        $this->db->execute(
            'INSERT INTO run_messages (channel, chat_id, message_id, run_id) VALUES (?, ?, ?, ?)
             ON CONFLICT (channel, chat_id, message_id) DO UPDATE SET run_id = excluded.run_id',
            [$channel, $chatId, $messageId, $runId],
        );
    }

    public function runOf(string $channel, string $chatId, string $messageId): ?int {
        $row = $this->db->execute('SELECT run_id FROM run_messages WHERE channel = ? AND chat_id = ? AND message_id = ?', [$channel, $chatId, $messageId])->fetchRow();
        $id = $row['run_id'] ?? null;
        return is_int($id) ? $id : null;
    }
}
