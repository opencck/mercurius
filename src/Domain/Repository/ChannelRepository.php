<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Group\ChannelPost;
use OpenCCK\Domain\Group\PublicChannel;

/**
 * Channels where the bot is an admin, and their posts.
 */
interface ChannelRepository {
    public function find(string $channel, string $chatId): ?PublicChannel;

    /** @return list<PublicChannel> available ones first */
    public function all(): array;

    /** Records the channel as Telegram reported it; returns what was there before, null for a new one. */
    public function save(PublicChannel $channel): ?PublicChannel;

    /** A new post or a new text of a known one; a post of an unknown channel is not kept. */
    public function savePost(string $channel, string $chatId, string $messageId, string $text, DateTimeImmutable $at, bool $edited): void;

    public function deletePost(string $channel, string $chatId, string $messageId): void;

    public function post(string $channel, string $chatId, string $messageId): ?ChannelPost;

    /** @return list<ChannelPost> the latest first */
    public function posts(string $channel, string $chatId, int $limit): array;
}
