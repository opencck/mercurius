<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * Read-only figures for the owner's reports: spend by model and day, recent and costliest runs.
 *
 * spec: BRIEF.md#трассировка-и-метрики — /usage, /runs
 */
interface TraceReportRepository {
    /** @return list<array{model: string, calls: int, input: int, output: int, cost: float}> costliest first */
    public function usageByModel(DateTimeImmutable $since): array;

    /** @return list<array{day: string, calls: int, cost: float}> newest first */
    public function usageByDay(DateTimeImmutable $since): array;

    /** @return list<array{id: int, cost: float, tokens: int}> */
    public function costliestRuns(DateTimeImmutable $since, int $limit): array;

    /** @return list<array{id: int, status: string, reason: ?string, model: string, turns: int, tokens: int, active_ms: int, cost: float, started: string}> newest first */
    public function recentRuns(int $limit): array;
}
