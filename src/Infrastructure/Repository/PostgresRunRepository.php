<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Repository\RunRepository;
use UnexpectedValueException;

/**
 * spec: BRIEF.md#лимиты — the budget is reserved by one conditional UPDATE, never checked afterwards
 * ref: docs/backendbytes-multi-agent-orchestrator-backend.md:L324-L342 (fetched 2026-11-16) — reserve the worst case,
 *      reconcile with the actual spend; a compare-and-swap there, a conditional UPDATE here
 */
final class PostgresRunRepository implements RunRepository {
    private const COLUMNS = 'id, conversation_id, principal_id, parent_run_id, model, status, stop_reason, max_turns, token_budget,
        run_timeout, tool_timeout, turns, tokens_used, tokens_reserved, active_ms, prompt_anchor, warned';

    public function __construct(private readonly PostgresLink $db) {}

    public function start(int $conversationId, int $principalId, string $model, RunLimits $limits, ?int $parentRunId = null): ?Run {
        try {
            $row = $this->db->execute(
                'INSERT INTO runs (conversation_id, principal_id, parent_run_id, model, status, max_turns, token_budget, run_timeout, tool_timeout)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 RETURNING ' . self::COLUMNS,
                [$conversationId, $principalId, $parentRunId, $model, RunStatus::Running->value, $limits->maxTurns, $limits->tokenBudget, $limits->runTimeout, $limits->toolTimeout],
            )->fetchRow();
        } catch (SqlQueryError $e) {
            if (str_contains($e->getMessage(), 'runs_one_active_per_conversation')) {
                return null;
            }
            throw $e;
        }
        return $row === null ? null : self::run($row);
    }

    public function find(int $id): ?Run {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM runs WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::run($row);
    }

    public function active(int $conversationId): ?Run {
        $row = $this->db->execute(
            'SELECT ' . self::COLUMNS . " FROM runs WHERE conversation_id = ? AND status IN ('running', 'waiting_approval')",
            [$conversationId],
        )->fetchRow();
        return $row === null ? null : self::run($row);
    }

    public function unfinished(): array {
        $runs = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . " FROM runs WHERE status = 'running' ORDER BY id") as $row) {
            $runs[] = self::run($row);
        }
        return $runs;
    }

    public function reserve(int $runId, int $tokens): bool {
        $row = $this->db->execute(
            'UPDATE runs SET tokens_reserved = tokens_reserved + ?, updated_at = now()
             WHERE id = ? AND tokens_used + tokens_reserved + ? <= token_budget
             RETURNING id',
            [$tokens, $runId, $tokens],
        )->fetchRow();
        return $row !== null;
    }

    public function settle(int $runId, int $reserved, int $actual, int $promptAnchor): void {
        $this->db->execute(
            'UPDATE runs SET tokens_reserved = GREATEST(0, tokens_reserved - ?), tokens_used = tokens_used + ?, turns = turns + 1,
                prompt_anchor = ?, updated_at = now()
             WHERE id = ?',
            [$reserved, $actual, $promptAnchor, $runId],
        );
    }

    public function addActiveTime(int $runId, int $ms): void {
        $this->db->execute('UPDATE runs SET active_ms = active_ms + ?, updated_at = now() WHERE id = ?', [$ms, $runId]);
    }

    public function markWarned(int $runId): void {
        $this->db->execute('UPDATE runs SET warned = true, updated_at = now() WHERE id = ?', [$runId]);
    }

    public function finish(int $runId, RunStatus $status, ?StopReason $reason): void {
        $this->db->execute(
            'UPDATE runs SET status = ?, stop_reason = ?, tokens_reserved = 0, updated_at = now() WHERE id = ?',
            [$status->value, $reason?->value, $runId],
        );
    }

    public function setStatus(int $runId, RunStatus $status): void {
        $this->db->execute('UPDATE runs SET status = ?, updated_at = now() WHERE id = ?', [$status->value, $runId]);
    }

    public function transition(int $runId, RunStatus $from, RunStatus $to): ?Run {
        $row = $this->db->execute(
            'UPDATE runs SET status = ?, updated_at = now() WHERE id = ? AND status = ? RETURNING ' . self::COLUMNS,
            [$to->value, $runId, $from->value],
        )->fetchRow();
        return $row === null ? null : self::run($row);
    }

    public function waitingApproval(): array {
        $runs = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . " FROM runs WHERE status = 'waiting_approval' ORDER BY id") as $row) {
            $runs[] = self::run($row);
        }
        return $runs;
    }

    /** @param array<string, mixed> $row */
    private static function run(array $row): Run {
        return new Run(
            id: self::int($row, 'id'),
            conversationId: self::int($row, 'conversation_id'),
            principalId: self::int($row, 'principal_id'),
            parentRunId: isset($row['parent_run_id']) ? self::int($row, 'parent_run_id') : null,
            model: self::string($row, 'model'),
            status: RunStatus::from(self::string($row, 'status')),
            stopReason: isset($row['stop_reason']) ? StopReason::from(self::string($row, 'stop_reason')) : null,
            limits: new RunLimits(self::int($row, 'max_turns'), self::int($row, 'token_budget'), self::int($row, 'run_timeout'), self::int($row, 'tool_timeout')),
            turns: self::int($row, 'turns'),
            tokensUsed: self::int($row, 'tokens_used'),
            tokensReserved: self::int($row, 'tokens_reserved'),
            activeMs: self::int($row, 'active_ms'),
            promptAnchor: self::int($row, 'prompt_anchor'),
            warned: ($row['warned'] ?? false) === true,
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed runs row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed runs row: %s', $key));
        }
        return $value;
    }
}
