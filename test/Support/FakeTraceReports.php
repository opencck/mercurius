<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Repository\TraceReportRepository;

/** Fixed report rows; records the period asked for. */
final class FakeTraceReports implements TraceReportRepository {
    public ?DateTimeImmutable $since = null;

    /**
     * @param list<array{model: string, calls: int, input: int, output: int, cost: float}> $models
     * @param list<array{day: string, calls: int, cost: float}> $days
     * @param list<array{id: int, cost: float, tokens: int}> $costliest
     * @param list<array{id: int, status: string, reason: ?string, model: string, turns: int, tokens: int, active_ms: int, cost: float, started: string}> $runs
     */
    public function __construct(
        public array $models = [],
        public array $days = [],
        public array $costliest = [],
        public array $runs = [],
    ) {}

    public function usageByModel(DateTimeImmutable $since): array {
        $this->since = $since;
        return $this->models;
    }

    public function usageByDay(DateTimeImmutable $since): array {
        return $this->days;
    }

    public function costliestRuns(DateTimeImmutable $since, int $limit): array {
        return array_slice($this->costliest, 0, $limit);
    }

    public function recentRuns(int $limit): array {
        return array_slice($this->runs, 0, $limit);
    }
}
