<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\GroupIgnoreRepository;

final class InMemoryGroupIgnoreRepository implements GroupIgnoreRepository {
    /** @var array<string, array<string, true>> */
    private array $who = [];

    public function add(string $channel, string $chatId, string $who, ?int $by): void {
        $this->who[$channel . "\0" . $chatId][$who] = true;
    }

    public function remove(string $channel, string $chatId, string $who): bool {
        $found = isset($this->who[$channel . "\0" . $chatId][$who]);
        unset($this->who[$channel . "\0" . $chatId][$who]);
        return $found;
    }

    public function list(string $channel, string $chatId): array {
        $list = array_map('strval', array_keys($this->who[$channel . "\0" . $chatId] ?? []));
        sort($list);
        return $list;
    }

    public function ignored(string $channel, string $chatId, string $userId, ?string $userName): bool {
        $set = $this->who[$channel . "\0" . $chatId] ?? [];
        return isset($set[$userId]) || ($userName !== null && isset($set[mb_strtolower(ltrim($userName, '@'))]));
    }
}
