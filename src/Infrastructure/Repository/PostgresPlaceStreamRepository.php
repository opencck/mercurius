<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Peer\PlaceStream;
use OpenCCK\Domain\Repository\PlaceStreamRepository;
use UnexpectedValueException;

/** spec: brief/network.md#потоки-мест-для-агентов */
final class PostgresPlaceStreamRepository implements PlaceStreamRepository {
    private const COLUMNS = 'id, principal_id, channel, chat_id, thread_id, title, subscribed';

    public function __construct(private readonly PostgresLink $db) {}

    public function grant(int $principalId, string $channel, string $chatId, string $threadId, string $title, ?int $by): PlaceStream {
        $row = $this->db->execute(
            'INSERT INTO place_streams (principal_id, channel, chat_id, thread_id, title, created_by) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (principal_id, channel, chat_id, thread_id) DO UPDATE SET title = EXCLUDED.title
             RETURNING ' . self::COLUMNS,
            [$principalId, $channel, $chatId, $threadId, mb_substr($title, 0, 200), $by],
        )->fetchRow();
        return $row === null ? throw new UnexpectedValueException('stream not kept') : self::stream($row);
    }

    public function revoke(int $id): ?PlaceStream {
        $row = $this->db->execute('DELETE FROM place_streams WHERE id = ? RETURNING ' . self::COLUMNS, [$id])->fetchRow();
        return $row === null ? null : self::stream($row);
    }

    public function find(int $id): ?PlaceStream {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM place_streams WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::stream($row);
    }

    public function of(?int $principalId): array {
        $streams = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM place_streams WHERE (?::bigint IS NULL OR principal_id = ?) ORDER BY id', [$principalId, $principalId]) as $row) {
            $streams[] = self::stream($row);
        }
        return $streams;
    }

    public function setSubscribed(int $id, bool $subscribed): void {
        $this->db->execute('UPDATE place_streams SET subscribed = ? WHERE id = ?', [$subscribed, $id]);
    }

    public function listeners(string $channel, string $chatId): array {
        $streams = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM place_streams WHERE subscribed AND channel = ? AND chat_id = ? ORDER BY id', [$channel, $chatId]) as $row) {
            $streams[] = self::stream($row);
        }
        return $streams;
    }

    /** @param array<string, mixed> $row */
    private static function stream(array $row): PlaceStream {
        $int = static fn(string $k): int => isset($row[$k]) && is_int($row[$k]) ? $row[$k] : 0;
        $str = static fn(string $k): string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : '';
        return new PlaceStream($int('id'), $int('principal_id'), $str('channel'), $str('chat_id'), $str('thread_id'), $str('title'), ($row['subscribed'] ?? false) === true);
    }
}
