<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * How models have done as workers here: our own data next to the catalog's benchmarks.
 *
 * spec: brief/orchestration.md#часть-4--проверяющий-и-данные-трасс — failure rates in the choice
 */
interface ModelReliabilityRepository {
    /** Endings where the model let the worker down. */
    public const FAILURES = ['model_failed', 'invalid_calls', 'truncated', 'loop_guard'];

    /**
     * Worker runs that ended with an answer or with a failure of the model (stops and limits say nothing about it),
     * by model ref since the date, with the time of the latest one.
     *
     * @return array<string, array{runs: int, failures: int, last: int}> last — unix seconds
     */
    public function failures(DateTimeImmutable $since): array;

    /**
     * Duration of successful model calls since the date by model ref, in milliseconds.
     *
     * @return array<string, array{calls: int, p50: int, p95: int}>
     */
    public function latency(DateTimeImmutable $since): array;
}
