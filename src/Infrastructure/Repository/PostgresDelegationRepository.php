<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Agent\Delegation;
use OpenCCK\Domain\Agent\DelegationTask;
use OpenCCK\Domain\Repository\DelegationRepository;
use Throwable;
use UnexpectedValueException;

/**
 * spec: BRIEF.md#часть-3--фоновое-делегирование
 */
final class PostgresDelegationRepository implements DelegationRepository {
    private const COLUMNS = 'id, conversation_id, run_id, principal_id, call_id, reserved, status, report, delivered_at IS NOT NULL AS delivered';
    private const TASK_COLUMNS = 'delegation_id, position, task::text AS task, share, run_id, retry_run_id, report::text AS report';

    public function __construct(private readonly PostgresLink $db) {}

    public function create(int $conversationId, int $runId, int $principalId, string $callId, int $reserved, array $tasks): Delegation {
        $transaction = $this->db->beginTransaction();
        try {
            $row = $transaction->execute(
                'INSERT INTO delegations (conversation_id, run_id, principal_id, call_id, reserved) VALUES (?, ?, ?, ?, ?) RETURNING ' . self::COLUMNS,
                [$conversationId, $runId, $principalId, $callId, $reserved],
            )->fetchRow();
            if ($row === null) {
                throw new UnexpectedValueException('Delegation was not created');
            }
            $delegation = self::delegation($row);
            foreach ($tasks as $i => $task) {
                $transaction->execute(
                    'INSERT INTO delegation_tasks (delegation_id, position, task, share) VALUES (?, ?, ?::jsonb, ?)',
                    [$delegation->id, $i + 1, json_encode($task['task'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $task['share']],
                );
            }
            $transaction->commit();
            return $delegation;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function find(int $id): ?Delegation {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM delegations WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::delegation($row);
    }

    public function tasks(int $delegationId): array {
        $tasks = [];
        foreach ($this->db->execute('SELECT ' . self::TASK_COLUMNS . ' FROM delegation_tasks WHERE delegation_id = ? ORDER BY position', [$delegationId]) as $row) {
            $tasks[] = self::task($row);
        }
        return $tasks;
    }

    public function runningCount(int $conversationId): int {
        $row = $this->db->execute("SELECT count(*) AS n FROM delegations WHERE conversation_id = ? AND status = 'running'", [$conversationId])->fetchRow();
        return $row === null ? 0 : self::int($row, 'n');
    }

    public function running(?int $conversationId = null): array {
        $result = $conversationId === null
            ? $this->db->execute("SELECT " . self::COLUMNS . " FROM delegations WHERE status = 'running' ORDER BY id")
            : $this->db->execute("SELECT " . self::COLUMNS . " FROM delegations WHERE status = 'running' AND conversation_id = ? ORDER BY id", [$conversationId]);
        $delegations = [];
        foreach ($result as $row) {
            $delegations[] = self::delegation($row);
        }
        return $delegations;
    }

    public function undelivered(): array {
        $delegations = [];
        foreach ($this->db->execute("SELECT " . self::COLUMNS . " FROM delegations WHERE status = 'done' AND delivered_at IS NULL ORDER BY id") as $row) {
            $delegations[] = self::delegation($row);
        }
        return $delegations;
    }

    public function setTaskRun(int $delegationId, int $position, int $attempt, int $runId): void {
        $column = $attempt === 1 ? 'run_id' : 'retry_run_id';
        $this->db->execute("UPDATE delegation_tasks SET {$column} = ? WHERE delegation_id = ? AND position = ?", [$runId, $delegationId, $position]);
    }

    public function finishTask(int $delegationId, int $position, array $report): void {
        $this->db->execute(
            'UPDATE delegation_tasks SET report = ?::jsonb WHERE delegation_id = ? AND position = ?',
            [json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $delegationId, $position],
        );
    }

    public function finish(int $id, string $status, ?string $report): bool {
        $row = $this->db->execute(
            "UPDATE delegations SET status = ?, report = ?, finished_at = now() WHERE id = ? AND status = 'running' RETURNING id",
            [$status, $report, $id],
        )->fetchRow();
        return $row !== null;
    }

    public function markDelivered(int $id): void {
        $this->db->execute('UPDATE delegations SET delivered_at = now() WHERE id = ? AND delivered_at IS NULL', [$id]);
    }

    public function ownsRun(int $runId): bool {
        $row = $this->db->execute('SELECT 1 AS found FROM delegation_tasks WHERE run_id = ? OR retry_run_id = ? LIMIT 1', [$runId, $runId])->fetchRow();
        return $row !== null;
    }

    /** @param array<string, mixed> $row */
    private static function delegation(array $row): Delegation {
        return new Delegation(
            self::int($row, 'id'),
            self::int($row, 'conversation_id'),
            self::int($row, 'run_id'),
            self::int($row, 'principal_id'),
            self::string($row, 'call_id'),
            self::int($row, 'reserved'),
            self::string($row, 'status'),
            isset($row['report']) ? self::string($row, 'report') : null,
            ($row['delivered'] ?? false) === true,
        );
    }

    /** @param array<string, mixed> $row */
    private static function task(array $row): DelegationTask {
        return new DelegationTask(
            self::int($row, 'delegation_id'),
            self::int($row, 'position'),
            self::json(self::string($row, 'task')) ?? [],
            self::int($row, 'share'),
            isset($row['run_id']) ? self::int($row, 'run_id') : null,
            isset($row['retry_run_id']) ? self::int($row, 'retry_run_id') : null,
            isset($row['report']) ? self::json(self::string($row, 'report')) : null,
        );
    }

    /** @return array<string, mixed>|null */
    private static function json(string $json): ?array {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return null;
        }
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed delegations row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed delegations row: %s', $key));
        }
        return $value;
    }
}
