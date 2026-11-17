<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Group\ChannelPost;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Domain\Repository\ChannelRepository;

final class InMemoryChannelRepository implements ChannelRepository {
    /** @var array<string, PublicChannel> */
    private array $channels = [];
    /** @var array<string, array<string, ChannelPost>> */
    private array $posts = [];

    public function find(string $channel, string $chatId): ?PublicChannel {
        return $this->channels[$channel . ':' . $chatId] ?? null;
    }

    public function all(): array {
        $all = array_values($this->channels);
        usort($all, static fn(PublicChannel $a, PublicChannel $b): int => [!$a->available, $a->title] <=> [!$b->available, $b->title]);
        return $all;
    }

    public function save(PublicChannel $channel): ?PublicChannel {
        $before = $this->find($channel->channel, $channel->chatId);
        $this->channels[$channel->channel . ':' . $channel->chatId] = $channel;
        return $before;
    }

    public function savePost(string $channel, string $chatId, string $messageId, string $text, DateTimeImmutable $at, bool $edited): void {
        if ($this->find($channel, $chatId) === null) {
            return;
        }
        $old = $this->posts[$channel . ':' . $chatId][$messageId] ?? null;
        $this->posts[$channel . ':' . $chatId][$messageId] = $edited && $old !== null
            ? new ChannelPost($messageId, $text, $old->postedAt, $at)
            : new ChannelPost($messageId, $text, $old->postedAt ?? $at, $edited ? $at : null);
    }

    public function deletePost(string $channel, string $chatId, string $messageId): void {
        unset($this->posts[$channel . ':' . $chatId][$messageId]);
    }

    public function post(string $channel, string $chatId, string $messageId): ?ChannelPost {
        return $this->posts[$channel . ':' . $chatId][$messageId] ?? null;
    }

    public function posts(string $channel, string $chatId, int $limit): array {
        $posts = array_values($this->posts[$channel . ':' . $chatId] ?? []);
        usort($posts, static fn(ChannelPost $a, ChannelPost $b): int => $b->postedAt <=> $a->postedAt);
        return array_slice($posts, 0, $limit);
    }
}
