<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\ModelReliabilityRepository;

/**
 * Worker runs are runs with a parent; the model failed them when they ended with one of the model's failures.
 *
 * spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс
 */
final class PostgresModelReliabilityRepository implements ModelReliabilityRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function failures(DateTimeImmutable $since): array {
        $placeholders = implode(', ', array_fill(0, count(self::FAILURES), '?'));
        $stats = [];
        foreach ($this->db->execute(
            "SELECT model, count(*)::int AS runs, (count(*) FILTER (WHERE stop_reason IN ({$placeholders})))::int AS failures
             FROM runs
             WHERE parent_run_id IS NOT NULL AND status NOT IN ('running', 'waiting_approval') AND created_at >= to_timestamp(?)
             GROUP BY model",
            [...self::FAILURES, $since->getTimestamp()],
        ) as $row) {
            $model = $row['model'] ?? null;
            $runs = $row['runs'] ?? null;
            $failures = $row['failures'] ?? null;
            if (is_string($model) && is_int($runs) && is_int($failures)) {
                $stats[$model] = ['runs' => $runs, 'failures' => $failures];
            }
        }
        return $stats;
    }
}
