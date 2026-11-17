<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Repository\GroupRepository;

final class PostgresGroupRepository implements GroupRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function find(string $channel, string $chatId): ?Group {
        $row = $this->db->execute('SELECT channel, chat_id, title, status, mode FROM chat_groups WHERE channel = ? AND chat_id = ?', [$channel, $chatId])->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function create(string $channel, string $chatId, string $title): bool {
        return $this->db->execute(
            'INSERT INTO chat_groups (channel, chat_id, title) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING chat_id',
            [$channel, $chatId, $title],
        )->fetchRow() !== null;
    }

    public function decide(string $channel, string $chatId, string $status, string $mode): ?Group {
        $row = $this->db->execute(
            'UPDATE chat_groups SET status = ?, mode = ? WHERE channel = ? AND chat_id = ? RETURNING channel, chat_id, title, status, mode',
            [$status, $mode, $channel, $chatId],
        )->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT channel, chat_id, title, status, mode FROM chat_groups ORDER BY created_at') as $row) {
            $found[] = self::group($row);
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function group(array $row): Group {
        $s = static fn(string $k): string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : '';
        return new Group($s('channel'), $s('chat_id'), $s('title'), $s('status'), $s('mode'));
    }
}
