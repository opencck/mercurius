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

    public function delegation(DateTimeImmutable $since): array {
        $row = $this->db->execute(
            "SELECT
                (SELECT count(*) FROM runs WHERE parent_run_id IS NULL AND created_at >= to_timestamp(?))::int AS top,
                (SELECT count(DISTINCT parent_run_id) FROM runs WHERE parent_run_id IS NOT NULL AND created_at >= to_timestamp(?))::int AS delegating,
                (SELECT count(*) FROM runs r JOIN conversations c ON c.id = r.conversation_id
                  WHERE r.parent_run_id IS NOT NULL AND c.channel = 'worker' AND c.chat_id NOT LIKE '%-verify' AND c.chat_id NOT LIKE '%-retry'
                    AND r.created_at >= to_timestamp(?))::int AS workers,
                (SELECT count(*) FROM runs r JOIN conversations c ON c.id = r.conversation_id
                  WHERE r.parent_run_id IS NOT NULL AND c.channel = 'worker' AND c.chat_id LIKE '%-retry' AND r.created_at >= to_timestamp(?))::int AS cascades,
                (SELECT count(*) FROM runs r JOIN conversations c ON c.id = r.conversation_id
                  WHERE r.parent_run_id IS NOT NULL AND c.channel = 'worker' AND c.chat_id LIKE '%-verify' AND r.created_at >= to_timestamp(?))::int AS verifiers,
                -- the same rule as the code: the first line that starts with a verdict, in the verifier's final answer
                (SELECT count(*) FROM runs r JOIN conversations c ON c.id = r.conversation_id
                  WHERE r.parent_run_id IS NOT NULL AND c.channel = 'worker' AND c.chat_id LIKE '%-verify' AND r.created_at >= to_timestamp(?)
                    AND substring(
                          (SELECT m.content FROM messages m WHERE m.run_id = r.id AND m.role = 'assistant'
                             AND (m.tool_calls IS NULL OR jsonb_array_length(m.tool_calls) = 0) ORDER BY m.id DESC LIMIT 1)
                          FROM '(?:^|\n)[[:space:]*_]*[Вв][Ее][Рр][Дд][Ии][Кк][Тт][[:space:]*_]*[:—–-][[:space:]*_]*([А-Яа-я]+)') IN ('отклонено', 'Отклонено', 'ОТКЛОНЕНО'))::int AS rejected,
                (SELECT count(*) FROM delegations WHERE created_at >= to_timestamp(?))::int AS background",
            array_fill(0, 7, $since->getTimestamp()),
        )->fetchRow() ?? [];
        return [
            'top' => self::int($row, 'top'),
            'delegating' => self::int($row, 'delegating'),
            'workers' => self::int($row, 'workers'),
            'cascades' => self::int($row, 'cascades'),
            'verifiers' => self::int($row, 'verifiers'),
            'rejected' => self::int($row, 'rejected'),
            'background' => self::int($row, 'background'),
        ];
    }

    public function workerModels(DateTimeImmutable $since): array {
        $failures = "'" . implode("', '", \OpenCCK\Domain\Repository\ModelReliabilityRepository::FAILURES) . "'";
        $rows = [];
        foreach ($this->db->execute(
            "SELECT r.model, count(*)::int AS runs,
                    (count(*) FILTER (WHERE r.stop_reason = 'answered'))::int AS answered,
                    (count(*) FILTER (WHERE r.stop_reason IN ({$failures})))::int AS failed,
                    coalesce(sum(r.tokens_used), 0)::int AS tokens,
                    coalesce(sum((SELECT sum(cost_usd) FROM llm_calls l WHERE l.run_id = r.id)), 0)::float8 AS cost,
                    coalesce(avg(r.active_ms), 0)::int AS active_ms
             FROM runs r
             WHERE r.parent_run_id IS NOT NULL AND r.status NOT IN ('running', 'waiting_approval') AND r.created_at >= to_timestamp(?)
             GROUP BY r.model ORDER BY runs DESC, r.model",
            [$since->getTimestamp()],
        ) as $row) {
            $rows[] = [
                'model' => self::str($row, 'model'),
                'runs' => self::int($row, 'runs'),
                'answered' => self::int($row, 'answered'),
                'failed' => self::int($row, 'failed'),
                'tokens' => self::int($row, 'tokens'),
                'cost' => self::float($row, 'cost'),
                'active_ms' => self::int($row, 'active_ms'),
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
