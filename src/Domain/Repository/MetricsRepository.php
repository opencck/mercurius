<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Counters for Prometheus, read from the database on every scrape, so they survive restarts.
 *
 * spec: brief/operations.md#настройки-агента — Prometheus
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

    /** @return list<array{kind: string, status: string, count: int}> worker runs: worker, retry or verifier, by status */
    public function workers(): array;

    /**
     * Step durations by kind as a cumulative histogram over $bounds (seconds).
     *
     * @param list<float> $bounds
     * @return list<array{kind: string, buckets: list<int>, sum: float, count: int}>
     */
    public function durations(array $bounds): array;

    /**
     * How many worker runs each finished top-level run started, as a histogram: runs with at most each bound of
     * workers, the total of workers and the number of runs.
     *
     * spec: brief/operations.md#настройки-агента — workers per run
     *
     * @param list<int> $bounds
     * @return array{buckets: list<int>, sum: int, count: int}
     */
    public function workersPerRun(array $bounds): array;
}
