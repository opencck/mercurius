<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\TraceReportRepository;

use function Mercurius\I18n\t;

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

    /**
     * A call belongs to its run's participant and conversation (a call without a run — memory, catalogue — to its
     * conversation, if any); the conversation gives the chat and the topic, the topic or the chat its project.
     *
     * spec: BRIEF.md#трассировка-и-метрики — spend by topics, projects, participants and periods
     */
    public function breakdown(\OpenCCK\Domain\Agent\SpendQuery $query): array {
        $zone = $query->from->getTimezone()->getName();
        [$select, $group] = match ($query->by) {
            'topic' => ['c.chat_id AS chat_id, c.thread_id AS thread, max(g.title) AS chat, max(pr.name) AS project, max(cp_person.display_name) AS person, \'\' AS label', 'c.chat_id, c.thread_id'],
            'chat' => ['c.chat_id AS chat_id, \'\' AS thread, max(g.title) AS chat, NULL AS project, max(cp_person.display_name) AS person, \'\' AS label', 'c.chat_id'],
            'project' => ['NULL AS chat_id, \'\' AS thread, NULL AS chat, pr.name AS project, NULL AS person, \'\' AS label', 'pr.name'],
            'participant' => ['NULL AS chat_id, \'\' AS thread, NULL AS chat, NULL AS project, max(coalesce(p.display_name, \'#\' || p.id::text)) AS person, \'\' AS label', 'p.id'],
            'model' => ['NULL AS chat_id, \'\' AS thread, NULL AS chat, NULL AS project, NULL AS person, l.model AS label', 'l.model'],
            default => ['NULL AS chat_id, \'\' AS thread, NULL AS chat, NULL AS project, NULL AS person, to_char(l.created_at AT TIME ZONE \'' . str_replace("'", '', $zone) . '\', \'YYYY-MM-DD\') AS label', '6'],
        };
        $where = ['l.created_at >= to_timestamp(?)', 'l.created_at < to_timestamp(?)'];
        $params = [$query->from->getTimestamp(), $query->to->getTimestamp()];
        if ($query->principalId !== null) {
            $where[] = 'coalesce(r.principal_id, c.principal_id) = ?';
            $params[] = $query->principalId;
        }
        if ($query->place !== null) {
            $where[] = 'c.channel = ? AND c.chat_id = ?';
            $params[] = $query->place[0];
            $params[] = $query->place[1];
            if ($query->place[2] !== '') {
                $where[] = 'c.thread_id = ?';
                $params[] = $query->place[2];
            }
        }
        $rows = [];
        foreach ($this->db->execute(
            "SELECT $select, count(*)::int AS calls, coalesce(sum(l.input_tokens + l.cache_read_tokens + l.cache_write_tokens), 0)::int AS input,
                    coalesce(sum(l.output_tokens), 0)::int AS output, coalesce(sum(l.cost_usd), 0)::float8 AS cost
             FROM llm_calls l
             LEFT JOIN runs r ON r.id = l.run_id
             LEFT JOIN conversations c ON c.id = coalesce(l.conversation_id, r.conversation_id)
             LEFT JOIN principals p ON p.id = coalesce(r.principal_id, c.principal_id)
             LEFT JOIN principals cp_person ON cp_person.id = c.principal_id
             LEFT JOIN chat_groups g ON g.channel = c.channel AND g.chat_id = c.chat_id
             LEFT JOIN chat_projects cp ON cp.channel = c.channel AND cp.chat_id = c.chat_id AND cp.thread_id = c.thread_id
             LEFT JOIN projects pr ON pr.id = cp.project_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY $group ORDER BY cost DESC, calls DESC LIMIT 40",
            $params,
        ) as $row) {
            $rows[] = [
                'chat' => self::nullable($row, 'chat'),
                'chatId' => self::nullable($row, 'chat_id'),
                'thread' => self::str($row, 'thread'),
                'project' => self::nullable($row, 'project'),
                'person' => self::nullable($row, 'person'),
                'label' => self::str($row, 'label'),
                'calls' => self::int($row, 'calls'),
                'input' => self::int($row, 'input'),
                'output' => self::int($row, 'output'),
                'cost' => self::float($row, 'cost'),
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function nullable(array $row, string $key): ?string {
        /** @var mixed $value */
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : null;
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

    public function decisions(int $runId): array {
        $found = [];
        foreach ($this->db->execute('SELECT method, class, model, reason FROM routing_decisions WHERE run_id = ? ORDER BY id', [$runId]) as $row) {
            $method = $row['method'] ?? null;
            $class = $row['class'] ?? null;
            $model = $row['model'] ?? null;
            $reason = $row['reason'] ?? null;
            if (is_string($method) && is_string($class) && is_string($model) && is_string($reason)) {
                $found[] = ['method' => $method, 'class' => $class, 'model' => $model, 'reason' => $reason];
            }
        }
        return $found;
    }

    public function recentRuns(int $limit): array {
        $rows = [];
        foreach ($this->db->execute(
            "SELECT r.id, r.status, r.stop_reason, r.model, r.turns, r.tokens_used AS tokens, r.active_ms::int AS active_ms,
                    to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS started,
                    coalesce((SELECT sum(cost_usd) FROM llm_calls c
                              WHERE c.run_id IN (SELECT r.id UNION ALL SELECT w.id FROM runs w WHERE w.parent_run_id = r.id)), 0)::float8 AS cost
             FROM runs r WHERE r.parent_run_id IS NULL ORDER BY r.id DESC LIMIT ?",
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
            t('repository.postgres_trace_report.select_select_count_from_runs'),
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

    public function toolsUsage(DateTimeImmutable $since): array {
        $rows = [];
        foreach ($this->db->execute(
            "SELECT name, count(*)::int AS calls, (count(*) FILTER (WHERE status = 'error'))::int AS errors,
                    coalesce(avg(duration_ms), 0)::int AS avg_ms
             FROM trace_spans WHERE kind = 'tool' AND started_at >= to_timestamp(?)
             GROUP BY name ORDER BY calls DESC, name LIMIT 30",
            [$since->getTimestamp()],
        ) as $row) {
            $rows[] = ['name' => self::str($row, 'name'), 'calls' => self::int($row, 'calls'), 'errors' => self::int($row, 'errors'), 'avg_ms' => self::int($row, 'avg_ms')];
        }
        return $rows;
    }

    public function longestSteps(DateTimeImmutable $since, int $limit): array {
        $rows = [];
        foreach ($this->db->execute(
            "SELECT run_id, kind, name, duration_ms FROM trace_spans
             WHERE kind <> 'run' AND duration_ms IS NOT NULL AND started_at >= to_timestamp(?)
             ORDER BY duration_ms DESC, id LIMIT ?",
            [$since->getTimestamp(), max(1, $limit)],
        ) as $row) {
            $rows[] = ['run' => self::int($row, 'run_id'), 'kind' => self::str($row, 'kind'), 'name' => self::str($row, 'name'), 'ms' => self::int($row, 'duration_ms')];
        }
        return $rows;
    }

    public function delegationTokens(DateTimeImmutable $since): array {
        $row = $this->db->execute(
            'SELECT coalesce(sum(r.tokens_used), 0)::bigint AS orchestrator,
                    coalesce(sum((SELECT sum(w.tokens_used) FROM runs w WHERE w.parent_run_id = r.id)), 0)::bigint AS workers
             FROM runs r
             WHERE r.parent_run_id IS NULL AND r.created_at >= to_timestamp(?)
               AND EXISTS (SELECT 1 FROM runs w WHERE w.parent_run_id = r.id)',
            [$since->getTimestamp()],
        )->fetchRow() ?? [];
        return ['orchestrator' => self::int($row, 'orchestrator'), 'workers' => self::int($row, 'workers')];
    }

    public function usageByPromptVersion(DateTimeImmutable $since): array {
        $rows = [];
        foreach ($this->db->execute(
            'SELECT c.prompt_version AS version, count(DISTINCT l.run_id)::int AS runs, count(*)::int AS calls,
                    coalesce(sum(l.cost_usd), 0)::float8 AS cost
             FROM llm_calls l LEFT JOIN conversations c ON c.id = l.conversation_id
             WHERE l.created_at >= to_timestamp(?)
             GROUP BY c.prompt_version ORDER BY c.prompt_version DESC NULLS LAST',
            [$since->getTimestamp()],
        ) as $row) {
            $rows[] = ['version' => self::version($row), 'runs' => self::int($row, 'runs'), 'calls' => self::int($row, 'calls'), 'cost' => self::float($row, 'cost')];
        }
        return $rows;
    }

    public function delegationByPromptVersion(DateTimeImmutable $since): array {
        $rows = [];
        foreach ($this->db->execute(
            'SELECT c.prompt_version AS version, count(*)::int AS top,
                    (count(*) FILTER (WHERE EXISTS (SELECT 1 FROM runs w WHERE w.parent_run_id = r.id)))::int AS delegating,
                    coalesce(sum((SELECT count(*) FROM runs w WHERE w.parent_run_id = r.id)), 0)::int AS workers,
                    coalesce(sum((SELECT sum(l.cost_usd) FROM llm_calls l JOIN runs w ON w.id = l.run_id WHERE w.parent_run_id = r.id)), 0)::float8 AS cost
             FROM runs r JOIN conversations c ON c.id = r.conversation_id
             WHERE r.parent_run_id IS NULL AND r.created_at >= to_timestamp(?)
             GROUP BY c.prompt_version ORDER BY c.prompt_version DESC NULLS LAST',
            [$since->getTimestamp()],
        ) as $row) {
            $rows[] = ['version' => self::version($row), 'top' => self::int($row, 'top'), 'delegating' => self::int($row, 'delegating'), 'workers' => self::int($row, 'workers'), 'cost' => self::float($row, 'cost')];
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function version(array $row): ?int {
        return isset($row['version']) && is_int($row['version']) ? $row['version'] : null;
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
