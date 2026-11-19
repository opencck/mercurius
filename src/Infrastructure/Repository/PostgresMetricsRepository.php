<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\MetricsRepository;

final class PostgresMetricsRepository implements MetricsRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function runs(): array {
        $rows = [];
        foreach ($this->db->query("SELECT status, coalesce(stop_reason, '') AS reason, count(*)::int AS count FROM runs GROUP BY 1, 2 ORDER BY 1, 2") as $row) {
            $rows[] = ['status' => self::str($row, 'status'), 'reason' => self::str($row, 'reason'), 'count' => self::int($row, 'count')];
        }
        return $rows;
    }

    public function models(): array {
        $rows = [];
        foreach ($this->db->query(
            'SELECT model, sum(input_tokens)::bigint AS input, sum(output_tokens)::bigint AS output, sum(cache_read_tokens)::bigint AS cache_read,
                    sum(cache_write_tokens)::bigint AS cache_write, sum(reasoning_tokens)::bigint AS reasoning, coalesce(sum(cost_usd), 0)::float8 AS cost
             FROM llm_calls GROUP BY model ORDER BY model',
        ) as $row) {
            $rows[] = [
                'model' => self::str($row, 'model'),
                'input' => self::int($row, 'input'),
                'output' => self::int($row, 'output'),
                'cache_read' => self::int($row, 'cache_read'),
                'cache_write' => self::int($row, 'cache_write'),
                'reasoning' => self::int($row, 'reasoning'),
                'cost' => self::float($row, 'cost'),
            ];
        }
        return $rows;
    }

    public function tools(): array {
        $rows = [];
        foreach ($this->db->query("SELECT name, status, count(*)::int AS count FROM trace_spans WHERE kind = 'tool' GROUP BY 1, 2 ORDER BY 1, 2") as $row) {
            $rows[] = ['tool' => self::str($row, 'name'), 'status' => self::str($row, 'status'), 'count' => self::int($row, 'count')];
        }
        return $rows;
    }

    public function approvals(): array {
        $rows = [];
        foreach ($this->db->query('SELECT status AS outcome, count(*)::int AS count FROM approvals GROUP BY 1 ORDER BY 1') as $row) {
            $rows[] = ['outcome' => self::str($row, 'outcome'), 'count' => self::int($row, 'count')];
        }
        return $rows;
    }

    public function workers(): array {
        $rows = [];
        foreach ($this->db->query(
            "SELECT CASE WHEN c.chat_id LIKE '%-verify' THEN 'verifier' WHEN c.chat_id LIKE '%-retry' THEN 'retry' ELSE 'worker' END AS kind,
                    r.status, count(*)::int AS count
             FROM runs r JOIN conversations c ON c.id = r.conversation_id
             WHERE r.parent_run_id IS NOT NULL AND c.channel = 'worker' GROUP BY 1, 2 ORDER BY 1, 2",
        ) as $row) {
            $rows[] = ['kind' => self::str($row, 'kind'), 'status' => self::str($row, 'status'), 'count' => self::int($row, 'count')];
        }
        return $rows;
    }

    public function durations(array $bounds): array {
        $columns = [];
        foreach ($bounds as $i => $bound) {
            $columns[] = sprintf('count(*) FILTER (WHERE duration_ms <= %d)::int AS b%d', (int) round($bound * 1000.0), $i);
        }
        $rows = [];
        foreach ($this->db->query(
            'SELECT kind, ' . implode(', ', $columns) . ', coalesce(sum(duration_ms), 0)::float8 / 1000 AS sum, count(*)::int AS count
             FROM trace_spans WHERE duration_ms IS NOT NULL GROUP BY kind ORDER BY kind',
        ) as $row) {
            $buckets = [];
            foreach (array_keys($bounds) as $i) {
                $buckets[] = self::int($row, 'b' . $i);
            }
            $rows[] = ['kind' => self::str($row, 'kind'), 'buckets' => $buckets, 'sum' => self::float($row, 'sum'), 'count' => self::int($row, 'count')];
        }
        return $rows;
    }

    public function workersPerRun(array $bounds): array {
        $columns = [];
        foreach ($bounds as $i => $bound) {
            $columns[] = sprintf('count(*) FILTER (WHERE workers <= %d)::int AS b%d', $bound, $i);
        }
        $row = $this->db->query(
            'SELECT ' . ($columns === [] ? '' : implode(', ', $columns) . ', ') . 'coalesce(sum(workers), 0)::int AS sum, count(*)::int AS count
             FROM (SELECT (SELECT count(*) FROM runs w WHERE w.parent_run_id = r.id) AS workers
                   FROM runs r WHERE r.parent_run_id IS NULL AND r.status NOT IN (\'running\', \'waiting_approval\')) per_run',
        )->fetchRow() ?? [];
        $buckets = [];
        foreach (array_keys($bounds) as $i) {
            $buckets[] = self::int($row, 'b' . $i);
        }
        return ['buckets' => $buckets, 'sum' => self::int($row, 'sum'), 'count' => self::int($row, 'count')];
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
