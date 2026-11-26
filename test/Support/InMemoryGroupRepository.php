<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Repository\GroupRepository;

final class InMemoryGroupRepository implements GroupRepository {
    /** @var array<string, Group> */
    public array $groups = [];
    /** @var array<string, string> channel:former number => channel:number */
    private array $former = [];

    public function find(string $channel, string $chatId): ?Group {
        return $this->groups[$channel . ':' . $chatId] ?? null;
    }

    public function setForum(string $channel, string $chatId, bool $forum): void {
        $this->with($channel, $chatId, static fn(Group $g): Group => new Group($g->channel, $g->chatId, $g->title, $g->status, $g->mode, $forum, $g->dailyTokens, $g->card));
    }

    public function create(string $channel, string $chatId, string $title): bool {
        if (isset($this->groups[$channel . ':' . $chatId])) {
            return false;
        }
        $this->groups[$channel . ':' . $chatId] = new Group($channel, $chatId, $title, Group::PENDING, Group::MENTION);
        return true;
    }

    public function decide(string $channel, string $chatId, string $status, string $mode): ?Group {
        return $this->with($channel, $chatId, static fn(Group $g): Group => new Group($g->channel, $g->chatId, $g->title, $status, $mode, $g->forum, $g->dailyTokens, $g->card));
    }

    public function setDailyTokens(string $channel, string $chatId, ?int $tokens): ?Group {
        return $this->with($channel, $chatId, static fn(Group $g): Group => new Group($g->channel, $g->chatId, $g->title, $g->status, $g->mode, $g->forum, $tokens, $g->card));
    }

    public function setCard(string $channel, string $chatId, ?string $card): void {
        $this->with($channel, $chatId, static fn(Group $g): Group => new Group($g->channel, $g->chatId, $g->title, $g->status, $g->mode, $g->forum, $g->dailyTokens, $card));
    }

    public function reset(string $channel, string $chatId): ?Group {
        return $this->with($channel, $chatId, static fn(Group $g): Group => new Group($g->channel, $g->chatId, $g->title, Group::PENDING, Group::MENTION, $g->forum, $g->dailyTokens, $g->card));
    }

    public function move(string $channel, string $fromChatId, string $toChatId): ?Group {
        $g = $this->groups[$channel . ':' . $fromChatId] ?? null;
        if ($g === null) {
            return null;
        }
        unset($this->groups[$channel . ':' . $fromChatId]);
        $this->former[$channel . ':' . $fromChatId] = $channel . ':' . $toChatId;
        $now = $this->groups[$channel . ':' . $toChatId] ?? null;
        $decided = $now !== null && $now->status !== Group::PENDING;
        return $this->groups[$channel . ':' . $toChatId] = new Group(
            $channel,
            $toChatId,
            $now->title ?? $g->title,
            $decided ? $now->status : $g->status,
            $decided ? $now->mode : $g->mode,
            $now->forum ?? false,
            $now->dailyTokens ?? $g->dailyTokens,
            $now?->card,
        );
    }

    public function formerly(string $channel, string $chatId): ?Group {
        $key = $this->former[$channel . ':' . $chatId] ?? null;
        return $key === null ? null : ($this->groups[$key] ?? null);
    }

    public function all(): array {
        return array_values($this->groups);
    }

    /** @param \Closure(Group): Group $change */
    private function with(string $channel, string $chatId, \Closure $change): ?Group {
        $g = $this->groups[$channel . ':' . $chatId] ?? null;
        return $g === null ? null : $this->groups[$channel . ':' . $chatId] = $change($g);
    }
}
