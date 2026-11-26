<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Repository\GroupRepository;

final class PostgresGroupRepository implements GroupRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function find(string $channel, string $chatId): ?Group {
        $row = $this->db->execute('SELECT channel, chat_id, title, status, mode, forum, daily_tokens, card FROM chat_groups WHERE channel = ? AND chat_id = ?', [$channel, $chatId])->fetchRow();
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
            'UPDATE chat_groups SET status = ?, mode = ? WHERE channel = ? AND chat_id = ? RETURNING channel, chat_id, title, status, mode, forum, daily_tokens, card',
            [$status, $mode, $channel, $chatId],
        )->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function move(string $channel, string $fromChatId, string $toChatId): ?Group {
        // a decision taken on the new number already stays; the old one fills a group that still waits
        $row = $this->db->execute(
            "WITH old AS (DELETE FROM chat_groups WHERE channel = ? AND chat_id = ? RETURNING channel, title, status, mode, daily_tokens)
             INSERT INTO chat_groups (channel, chat_id, title, status, mode, daily_tokens, former_chat_id)
             SELECT channel, ?, title, status, mode, daily_tokens, ? FROM old
             ON CONFLICT (channel, chat_id) DO UPDATE SET
                status = CASE WHEN chat_groups.status = 'pending' THEN EXCLUDED.status ELSE chat_groups.status END,
                mode = CASE WHEN chat_groups.status = 'pending' THEN EXCLUDED.mode ELSE chat_groups.mode END,
                daily_tokens = COALESCE(chat_groups.daily_tokens, EXCLUDED.daily_tokens),
                former_chat_id = EXCLUDED.former_chat_id
             RETURNING channel, chat_id, title, status, mode, forum, daily_tokens, card",
            [$channel, $fromChatId, $toChatId, $fromChatId],
        )->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function formerly(string $channel, string $chatId): ?Group {
        $row = $this->db->execute('SELECT channel, chat_id, title, status, mode, forum, daily_tokens, card FROM chat_groups WHERE channel = ? AND former_chat_id = ?', [$channel, $chatId])->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function setCard(string $channel, string $chatId, ?string $card): void {
        $this->db->execute('UPDATE chat_groups SET card = ? WHERE channel = ? AND chat_id = ?', [$card, $channel, $chatId]);
    }

    public function reset(string $channel, string $chatId): ?Group {
        $row = $this->db->execute(
            "UPDATE chat_groups SET status = 'pending', mode = 'mention' WHERE channel = ? AND chat_id = ? RETURNING channel, chat_id, title, status, mode, forum, daily_tokens, card",
            [$channel, $chatId],
        )->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT channel, chat_id, title, status, mode, forum, daily_tokens, card FROM chat_groups ORDER BY created_at') as $row) {
            $found[] = self::group($row);
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function group(array $row): Group {
        $s = static fn(string $k): string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : '';
        return new Group($s('channel'), $s('chat_id'), $s('title'), $s('status'), $s('mode'), ($row['forum'] ?? false) === true, is_numeric($row['daily_tokens'] ?? null) ? (int) $row['daily_tokens'] : null, $s('card') === '' ? null : $s('card'));
    }

    public function setDailyTokens(string $channel, string $chatId, ?int $tokens): ?Group {
        $row = $this->db->execute(
            'UPDATE chat_groups SET daily_tokens = ? WHERE channel = ? AND chat_id = ? RETURNING channel, chat_id, title, status, mode, forum, daily_tokens, card',
            [$tokens, $channel, $chatId],
        )->fetchRow();
        return $row === null ? null : self::group($row);
    }

    public function setForum(string $channel, string $chatId, bool $forum): void {
        $this->db->execute('UPDATE chat_groups SET forum = ? WHERE channel = ? AND chat_id = ? AND forum <> ?', [$forum, $channel, $chatId, $forum]);
    }
}
