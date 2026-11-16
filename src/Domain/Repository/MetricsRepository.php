<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Counters for Prometheus, read from the database on every scrape, so they survive restarts.
 *
 * spec: BRIEF.md#трассировка-и-метрики — Prometheus
 */
interface MetricsRepository {
    /** @return list<array{status: string, reason: string, count: int}> */
    public function runs(): array;

    /** @return list<array{model: string, input: int, output: int, cache_read: int, cache_write: int, reasoning: int, cost: float}> */
    public function models(): array;

    /** @return list<array{tool: string, status: string, count: int}> */
    public function tools(): array;

    /** @return list<array{outcome: string, count: int}> */
    public function approvals(): array;

    /**
     * Step durations by kind as a cumulative histogram over $bounds (seconds).
     *
     * @param list<float> $bounds
     * @return list<array{kind: string, buckets: list<int>, sum: float, count: int}>
     */
    public function durations(array $bounds): array;
}
