<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Repository\GroupRepository;

final class InMemoryGroupRepository implements GroupRepository {
    /** @var array<string, Group> */
    public array $groups = [];

    public function find(string $channel, string $chatId): ?Group {
        return $this->groups[$channel . ':' . $chatId] ?? null;
    }

    public function create(string $channel, string $chatId, string $title): bool {
        if (isset($this->groups[$channel . ':' . $chatId])) {
            return false;
        }
        $this->groups[$channel . ':' . $chatId] = new Group($channel, $chatId, $title, Group::PENDING, Group::MENTION);
        return true;
    }

    public function decide(string $channel, string $chatId, string $status, string $mode): ?Group {
        $g = $this->groups[$channel . ':' . $chatId] ?? null;
        if ($g === null) {
            return null;
        }
        return $this->groups[$channel . ':' . $chatId] = new Group($g->channel, $g->chatId, $g->title, $status, $mode);
    }

    public function all(): array {
        return array_values($this->groups);
    }
}
