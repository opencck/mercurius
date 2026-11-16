<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\ModelRef;
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
            "SELECT model, count(*)::int AS runs, (count(*) FILTER (WHERE stop_reason <> 'answered'))::int AS failures,
                    extract(epoch FROM max(created_at))::bigint AS last
             FROM runs
             WHERE parent_run_id IS NOT NULL AND created_at >= to_timestamp(?) AND stop_reason IN ('answered', {$placeholders})
             GROUP BY model",
            [$since->getTimestamp(), ...self::FAILURES],
        ) as $row) {
            $model = $row['model'] ?? null;
            $runs = $row['runs'] ?? null;
            $failures = $row['failures'] ?? null;
            $last = $row['last'] ?? null;
            if (is_string($model) && is_int($runs) && is_int($failures) && is_int($last)) {
                $stats[$model] = ['runs' => $runs, 'failures' => $failures, 'last' => $last];
            }
        }
        return $stats;
    }

    public function latency(DateTimeImmutable $since): array {
        $stats = [];
        foreach ($this->db->execute(
            "SELECT model, count(*)::int AS calls,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY duration_ms)::int AS p50,
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms)::int AS p95
             FROM llm_calls WHERE created_at >= to_timestamp(?) AND error_kind IS NULL GROUP BY model",
            [$since->getTimestamp()],
        ) as $row) {
            $model = $row['model'] ?? null;
            $calls = $row['calls'] ?? null;
            $p50 = $row['p50'] ?? null;
            $p95 = $row['p95'] ?? null;
            if (!is_string($model) || !is_int($calls) || !is_int($p50) || !is_int($p95)) {
                continue;
            }
            // Calls record the model as the provider named it: a bare id is OpenRouter's.
            try {
                $key = (string) ModelRef::parse($model);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $stats[$key] = ['calls' => $calls, 'p50' => $p50, 'p95' => $p95];
        }
        return $stats;
    }
}
