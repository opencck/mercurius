<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Closure;
use OpenCCK\Domain\Repository\GroupTalkRepository;

/** The talk of groups in the memory of the process: for tests and a run without a database. */
final class InMemoryGroupTalkRepository implements GroupTalkRepository {
    private const KEEP = 100;

    /** @var array<string, list<array{int, string}>> chat:thread => [time, line] */
    private array $talk = [];
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(?Closure $clock = null) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function add(string $channel, string $chatId, string $threadId, string $line): void {
        $key = $channel . ':' . $chatId . ':' . $threadId;
        $this->talk[$key][] = [($this->clock)(), $line];
        $this->talk[$key] = array_slice($this->talk[$key], -self::KEEP);
    }

    public function recent(string $channel, string $chatId, string $threadId, int $limit, int $seconds): array {
        $since = ($this->clock)() - $seconds;
        $lines = [];
        foreach ($this->talk[$channel . ':' . $chatId . ':' . $threadId] ?? [] as [$at, $line]) {
            if ($at >= $since) {
                $lines[] = $line;
            }
        }
        return array_slice($lines, -$limit);
    }

    public function forget(string $channel, string $chatId): void {
        foreach (array_keys($this->talk) as $key) {
            if (str_starts_with($key, $channel . ':' . $chatId . ':')) {
                unset($this->talk[$key]);
            }
        }
    }
}
