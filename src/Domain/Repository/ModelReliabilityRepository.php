<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * How models have done as workers here: our own data next to the catalog's benchmarks.
 *
 * spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс — failure rates in the choice
 */
interface ModelReliabilityRepository {
    /** Endings where the model let the worker down. */
    public const FAILURES = ['model_failed', 'invalid_calls', 'truncated', 'loop_guard'];

    /** @return array<string, array{runs: int, failures: int}> finished worker runs by model ref since the date */
    public function failures(DateTimeImmutable $since): array;
}
