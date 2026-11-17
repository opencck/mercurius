<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\RunMessageRepository;

final class InMemoryRunMessageRepository implements RunMessageRepository {
    /** @var array<string, int> */
    public array $runs = [];

    public function record(int $runId, string $channel, string $chatId, string $messageId): void {
        $this->runs[$channel . ':' . $chatId . ':' . $messageId] = $runId;
    }

    public function runOf(string $channel, string $chatId, string $messageId): ?int {
        return $this->runs[$channel . ':' . $chatId . ':' . $messageId] ?? null;
    }
}
