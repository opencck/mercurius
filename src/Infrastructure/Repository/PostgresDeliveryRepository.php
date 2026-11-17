<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Agent\Delivery;
use OpenCCK\Domain\Repository\DeliveryRepository;

final class PostgresDeliveryRepository implements DeliveryRepository {
    private const COLUMNS = 'id, run_id, channel, chat_id, thread_id, reply_to, text, parse_mode, sent, status, attempts, extract(epoch FROM created_at)::bigint AS created';

    public function __construct(private readonly PostgresLink $db) {}

    public function open(?int $runId, string $channel, string $chatId, ?string $threadId, ?string $replyTo, string $text, string $parseMode, ?int $createdAt = null): int {
        $row = $this->db->execute(
            'INSERT INTO delivery_obligations (run_id, channel, chat_id, thread_id, reply_to, text, parse_mode, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, coalesce(to_timestamp(?::bigint), now())) RETURNING id',
            [$runId, $channel, $chatId, $threadId, $replyTo, $text, $parseMode, $createdAt],
        )->fetchRow();
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : 0;
    }

    public function attempting(int $id): void {
        $this->db->execute("UPDATE delivery_obligations SET status = 'attempting', updated_at = now() WHERE id = ?", [$id]);
    }

    public function progress(int $id, int $sent): void {
        $this->db->execute('UPDATE delivery_obligations SET sent = ?, updated_at = now() WHERE id = ?', [$sent, $id]);
    }

    public function delivered(int $id): void {
        $this->db->execute("UPDATE delivery_obligations SET status = 'delivered', updated_at = now() WHERE id = ?", [$id]);
        // delivered answers are not needed for long
        $this->db->execute("DELETE FROM delivery_obligations WHERE status IN ('delivered', 'abandoned') AND updated_at < now() - interval '7 days'");
    }

    public function failed(int $id, string $error): void {
        $this->db->execute("UPDATE delivery_obligations SET status = 'failed', attempts = attempts + 1, last_error = ?, updated_at = now() WHERE id = ?", [mb_substr($error, 0, 500), $id]);
    }

    public function abandon(int $id): void {
        $this->db->execute("UPDATE delivery_obligations SET status = 'abandoned', updated_at = now() WHERE id = ?", [$id]);
    }

    public function undelivered(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . " FROM delivery_obligations WHERE status IN ('pending', 'attempting', 'failed') ORDER BY id") as $row) {
            $found[] = self::delivery($row);
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function delivery(array $row): Delivery {
        $s = static fn(string $k): ?string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : null;
        $i = static fn(string $k): int => isset($row[$k]) && is_int($row[$k]) ? $row[$k] : 0;
        return new Delivery(
            $i('id'),
            isset($row['run_id']) && is_int($row['run_id']) ? $row['run_id'] : null,
            $s('channel') ?? '',
            $s('chat_id') ?? '',
            $s('thread_id'),
            $s('reply_to'),
            $s('text') ?? '',
            $s('parse_mode') ?? 'plain',
            $i('sent'),
            $s('status') ?? Delivery::FAILED,
            $i('attempts'),
            $i('created'),
        );
    }
}
