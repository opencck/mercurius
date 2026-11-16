<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\TraceReportRepository;

/** Spend comes from llm_calls (the provider's cost), runs from runs; one place for the owner's reports. */
final class PostgresTraceReportRepository implements TraceReportRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function usageByModel(DateTimeImmutable $since): array {
        $rows = [];
        foreach ($this->db->execute(
            'SELECT model, count(*)::int AS calls, sum(input_tokens + cache_read_tokens + cache_write_tokens)::int AS input,
                    sum(output_tokens)::int AS output, coalesce(sum(cost_usd), 0)::float8 AS cost
             FROM llm_calls WHERE created_at >= to_timestamp(?) GROUP BY model ORDER BY cost DESC, calls DESC',
            [$since->getTimestamp()],
        ) as $row) {
            $rows[] = ['model' => self::str($row, 'model'), 'calls' => self::int($row, 'calls'), 'input' => self::int($row, 'input'), 'output' => self::int($row, 'output'), 'cost' => self::float($row, 'cost')];
        }
        return $rows;
    }

    public function usageByDay(DateTimeImmutable $since): array {
        $rows = [];
        foreach ($this->db->execute(
            "SELECT to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day, count(*)::int AS calls, coalesce(sum(cost_usd), 0)::float8 AS cost
             FROM llm_calls WHERE created_at >= to_timestamp(?) GROUP BY 1 ORDER BY 1 DESC",
            [$since->getTimestamp()],
        ) as $row) {
            $rows[] = ['day' => self::str($row, 'day'), 'calls' => self::int($row, 'calls'), 'cost' => self::float($row, 'cost')];
        }
        return $rows;
    }

    public function costliestRuns(DateTimeImmutable $since, int $limit): array {
        $rows = [];
        foreach ($this->db->execute(
            'SELECT run_id AS id, coalesce(sum(cost_usd), 0)::float8 AS cost, sum(input_tokens + cache_read_tokens + cache_write_tokens + output_tokens)::int AS tokens
             FROM llm_calls WHERE run_id IS NOT NULL AND created_at >= to_timestamp(?) GROUP BY run_id ORDER BY cost DESC LIMIT ?',
            [$since->getTimestamp(), $limit],
        ) as $row) {
            $rows[] = ['id' => self::int($row, 'id'), 'cost' => self::float($row, 'cost'), 'tokens' => self::int($row, 'tokens')];
        }
        return $rows;
    }

    public function recentRuns(int $limit): array {
        $rows = [];
        foreach ($this->db->execute(
            "SELECT r.id, r.status, r.stop_reason, r.model, r.turns, r.tokens_used AS tokens, r.active_ms::int AS active_ms,
                    to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS started,
                    coalesce((SELECT sum(cost_usd) FROM llm_calls c WHERE c.run_id = r.id), 0)::float8 AS cost
             FROM runs r ORDER BY r.id DESC LIMIT ?",
            [$limit],
        ) as $row) {
            $reason = $row['stop_reason'] ?? null;
            $rows[] = [
                'id' => self::int($row, 'id'),
                'status' => self::str($row, 'status'),
                'reason' => is_string($reason) ? $reason : null,
                'model' => self::str($row, 'model'),
                'turns' => self::int($row, 'turns'),
                'tokens' => self::int($row, 'tokens'),
                'active_ms' => self::int($row, 'active_ms'),
                'cost' => self::float($row, 'cost'),
                'started' => self::str($row, 'started'),
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : 0;
    }

    /** @param array<string, mixed> $row */
    private static function float(array $row, string $key): float {
        return isset($row[$key]) && is_float($row[$key]) ? $row[$key] : 0.0;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : '';
    }
}
