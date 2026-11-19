<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\TraceReviewRepository;

/**
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the review reads real work only: runs of the eval channel (the gate's
 *       test runs) are left out, so a run of the gate does not change what the review says
 */
final class PostgresTraceReviewRepository implements TraceReviewRepository {
    /** runs of conversations the review leaves out */
    private const REAL = "r.conversation_id NOT IN (SELECT id FROM conversations WHERE channel = 'eval')";

    public function __construct(private readonly PostgresLink $db) {}

    public function review(DateTimeImmutable $since): array {
        $at = $since->getTimestamp();
        $stops = [];
        foreach ($this->db->execute(
            "SELECT coalesce(stop_reason, status) AS reason, count(*) AS runs, coalesce(sum(tokens_used), 0)::bigint AS tokens
             FROM runs r WHERE created_at >= to_timestamp(?) AND parent_run_id IS NULL AND " . self::REAL . " GROUP BY 1 ORDER BY 2 DESC",
            [$at],
        ) as $row) {
            $stops[] = ['reason' => self::str($row, 'reason'), 'runs' => self::int($row, 'runs'), 'tokens' => self::int($row, 'tokens')];
        }
        $tools = [];
        foreach ($this->db->execute(
            "SELECT t.name, count(*) AS calls, count(*) FILTER (WHERE t.status = 'failed') AS failed,
                    count(*) FILTER (WHERE t.status = 'denied') AS denied,
                    coalesce((array_agg(t.result_preview ORDER BY t.finished_at DESC) FILTER (WHERE t.status = 'failed'))[1], '') AS sample
             FROM tool_calls t JOIN runs r ON r.id = t.run_id WHERE r.created_at >= to_timestamp(?) AND " . self::REAL . "
             GROUP BY t.name ORDER BY 3 DESC, 4 DESC, 2 DESC LIMIT 15",
            [$at],
        ) as $row) {
            $tools[] = ['name' => self::str($row, 'name'), 'calls' => self::int($row, 'calls'), 'failed' => self::int($row, 'failed'), 'denied' => self::int($row, 'denied'), 'sample' => mb_substr(self::str($row, 'sample'), 0, 300)];
        }
        $retried = $this->db->execute(
            "SELECT count(*) AS n FROM tool_calls a JOIN tool_calls d ON d.run_id = a.run_id AND d.name = a.name AND d.status = 'denied' AND a.position > d.position
             JOIN runs r ON r.id = a.run_id WHERE r.created_at >= to_timestamp(?) AND " . self::REAL,
            [$at],
        )->fetchRow();
        $costly = [];
        foreach ($this->db->execute(
            'SELECT id, tokens_used, turns, coalesce(stop_reason, status) AS reason FROM runs r WHERE created_at >= to_timestamp(?) AND ' . self::REAL . ' ORDER BY tokens_used DESC LIMIT 5',
            [$at],
        ) as $row) {
            $costly[] = ['run' => self::int($row, 'id'), 'tokens' => self::int($row, 'tokens_used'), 'turns' => self::int($row, 'turns'), 'reason' => self::str($row, 'reason')];
        }
        $spend = [];
        foreach ($this->db->execute(
            "SELECT purpose, coalesce(sum(cost_usd), 0)::float8 AS usd, count(*) AS calls FROM llm_calls
             WHERE created_at >= to_timestamp(?) AND (conversation_id IS NULL OR conversation_id NOT IN (SELECT id FROM conversations WHERE channel = 'eval'))
             GROUP BY purpose ORDER BY 2 DESC",
            [$at],
        ) as $row) {
            $usd = $row['usd'] ?? 0.0;
            $spend[] = ['purpose' => self::str($row, 'purpose'), 'usd' => is_float($usd) ? $usd : 0.0, 'calls' => self::int($row, 'calls')];
        }
        // ref: references/claude-cookbooks/managed_agents/CMA_agent_security_review.ipynb@ce8b262 (log_review_utils.py,
        //      structural rules) — rules against the grant, here against the participant's policy: calls it did not
        //      allow, and an outward call within six calls after the owner refused one (retry-after-refusal)
        $refused = [];
        foreach ($this->db->execute(
            'SELECT t.name, count(*) AS calls, count(DISTINCT t.run_id) AS runs FROM tool_calls t JOIN runs r ON r.id = t.run_id
             WHERE t.refused AND r.created_at >= to_timestamp(?) AND ' . self::REAL . ' GROUP BY t.name ORDER BY 2 DESC LIMIT 10',
            [$at],
        ) as $row) {
            $refused[] = ['name' => self::str($row, 'name'), 'calls' => self::int($row, 'calls'), 'runs' => self::int($row, 'runs')];
        }
        $afterRefusal = [];
        foreach ($this->db->execute(
            "SELECT DISTINCT ON (a.run_id, a.call_id) a.run_id AS run, d.name AS denied, a.name AS then
             FROM tool_calls d JOIN tool_calls a ON a.run_id = d.run_id AND a.position > d.position AND a.position <= d.position + 6
             JOIN runs r ON r.id = d.run_id
             WHERE d.status = 'denied' AND a.danger <> 'read' AND a.status <> 'denied' AND r.created_at >= to_timestamp(?) AND " . self::REAL . "
             ORDER BY a.run_id DESC, a.call_id LIMIT 10",
            [$at],
        ) as $row) {
            $afterRefusal[] = ['run' => self::int($row, 'run'), 'denied' => self::str($row, 'denied'), 'then' => self::str($row, 'then')];
        }
        return ['stops' => $stops, 'tools' => $tools, 'retried' => self::int($retried ?? [], 'n'), 'costly' => $costly, 'spend' => $spend, 'refused' => $refused, 'afterRefusal' => $afterRefusal];
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : 0;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : '';
    }
}
