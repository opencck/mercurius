<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\Domain\Group\ChannelPost;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Domain\Repository\ChannelRepository;

final class PostgresChannelRepository implements ChannelRepository {
    private const CHANNEL = 'channel, chat_id, title, can_post, can_edit, can_delete, available';
    private const POST = "message_id, text, to_char(posted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') AS posted, to_char(edited_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') AS edited";

    public function __construct(private readonly PostgresLink $db) {}

    public function find(string $channel, string $chatId): ?PublicChannel {
        $row = $this->db->execute('SELECT ' . self::CHANNEL . ' FROM chat_channels WHERE channel = ? AND chat_id = ?', [$channel, $chatId])->fetchRow();
        return $row === null ? null : self::channel($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::CHANNEL . ' FROM chat_channels ORDER BY NOT available, title') as $row) {
            $found[] = self::channel($row);
        }
        return $found;
    }

    public function save(PublicChannel $channel): ?PublicChannel {
        $before = $this->find($channel->channel, $channel->chatId);
        $this->db->execute(
            'INSERT INTO chat_channels (channel, chat_id, title, can_post, can_edit, can_delete, available) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (channel, chat_id) DO UPDATE SET title = excluded.title, can_post = excluded.can_post, can_edit = excluded.can_edit,
                can_delete = excluded.can_delete, available = excluded.available, updated_at = now()',
            [$channel->channel, $channel->chatId, $channel->title, $channel->canPost, $channel->canEdit, $channel->canDelete, $channel->available],
        );
        return $before;
    }

    public function savePost(string $channel, string $chatId, string $messageId, string $text, DateTimeImmutable $at, bool $edited): void {
        $time = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        $this->db->execute(
            'INSERT INTO channel_posts (channel, chat_id, message_id, text, posted_at, edited_at)
             SELECT ?, ?, ?, ?, ?::timestamptz, CASE WHEN ? THEN ?::timestamptz END
             WHERE EXISTS (SELECT 1 FROM chat_channels WHERE channel = ? AND chat_id = ?)
             ON CONFLICT (channel, chat_id, message_id) DO UPDATE SET text = excluded.text,
                edited_at = CASE WHEN ? THEN excluded.edited_at ELSE channel_posts.edited_at END',
            [$channel, $chatId, $messageId, $text, $time, $edited, $time, $channel, $chatId, $edited],
        );
    }

    public function deletePost(string $channel, string $chatId, string $messageId): void {
        $this->db->execute('DELETE FROM channel_posts WHERE channel = ? AND chat_id = ? AND message_id = ?', [$channel, $chatId, $messageId]);
    }

    public function post(string $channel, string $chatId, string $messageId): ?ChannelPost {
        $row = $this->db->execute('SELECT ' . self::POST . ' FROM channel_posts WHERE channel = ? AND chat_id = ? AND message_id = ?', [$channel, $chatId, $messageId])->fetchRow();
        return $row === null ? null : self::post_($row);
    }

    public function posts(string $channel, string $chatId, int $limit): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::POST . ' FROM channel_posts WHERE channel = ? AND chat_id = ? ORDER BY posted_at DESC, message_id DESC LIMIT ?', [$channel, $chatId, $limit]) as $row) {
            $found[] = self::post_($row);
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function channel(array $row): PublicChannel {
        $s = static fn(string $k): string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : '';
        $b = static fn(string $k): bool => ($row[$k] ?? false) === true;
        return new PublicChannel($s('channel'), $s('chat_id'), $s('title'), $b('can_post'), $b('can_edit'), $b('can_delete'), $b('available'));
    }

    /** @param array<string, mixed> $row */
    private static function post_(array $row): ChannelPost {
        $s = static fn(string $k): string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : '';
        $utc = new DateTimeZone('UTC');
        $edited = $s('edited');
        return new ChannelPost($s('message_id'), $s('text'), new DateTimeImmutable($s('posted') === '' ? 'now' : $s('posted'), $utc), $edited === '' ? null : new DateTimeImmutable($edited, $utc));
    }
}
