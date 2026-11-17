<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Repository\JobRepository;
use UnexpectedValueException;

final class PostgresJobRepository implements JobRepository {
    private const COLUMNS = "id, principal_id, channel, chat_id, title, prompt, schedule, extract(epoch FROM next_run_at)::bigint AS next, runs, last_result, pending_report, status, hook_secret";

    public function __construct(private readonly PostgresLink $db) {}

    public function create(int $principalId, string $channel, string $chatId, string $title, string $prompt, string $schedule, ?DateTimeImmutable $nextRunAt): Job {
        $row = $this->db->execute(
            'INSERT INTO jobs (principal_id, channel, chat_id, title, prompt, schedule, next_run_at) VALUES (?, ?, ?, ?, ?, ?, to_timestamp(?)) RETURNING ' . self::COLUMNS,
            [$principalId, $channel, $chatId, $title, $prompt, $schedule, $nextRunAt?->getTimestamp()],
        )->fetchRow();
        return self::job($row ?? throw new UnexpectedValueException('Job was not created'));
    }

    public function find(int $id): ?Job {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM jobs WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::job($row);
    }

    public function list(?int $principalId): array {
        $found = [];
        foreach ($this->db->execute(
            "SELECT " . self::COLUMNS . " FROM jobs WHERE status <> 'done' AND (?::bigint IS NULL OR principal_id = ?) ORDER BY id DESC LIMIT 50",
            [$principalId, $principalId],
        ) as $row) {
            $found[] = self::job($row);
        }
        return $found;
    }

    public function due(DateTimeImmutable $now, int $limit): array {
        $found = [];
        foreach ($this->db->execute(
            "SELECT " . self::COLUMNS . " FROM jobs WHERE status = 'active' AND NOT running AND next_run_at <= to_timestamp(?) ORDER BY next_run_at LIMIT ?",
            [$now->getTimestamp(), $limit],
        ) as $row) {
            $found[] = self::job($row);
        }
        return $found;
    }

    public function claim(int $id, DateTimeImmutable $now, ?DateTimeImmutable $next): bool {
        return $this->db->execute(
            "UPDATE jobs SET running = true, last_run_at = now(), next_run_at = to_timestamp(?), runs = runs + 1
             WHERE id = ? AND status = 'active' AND NOT running AND next_run_at <= to_timestamp(?) RETURNING id",
            [$next?->getTimestamp(), $id, $now->getTimestamp()],
        )->fetchRow() !== null;
    }

    public function finish(int $id, string $result, ?string $pendingReport): void {
        $this->db->execute(
            "UPDATE jobs SET running = false, last_result = ?, pending_report = ?, status = CASE WHEN next_run_at IS NULL AND schedule <> 'event' THEN 'done' ELSE status END WHERE id = ?",
            [$result, $pendingReport, $id],
        );
    }

    public function pendingReports(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . ' FROM jobs WHERE pending_report IS NOT NULL ORDER BY id') as $row) {
            $found[] = self::job($row);
        }
        return $found;
    }

    public function clearPending(int $id): void {
        $this->db->execute('UPDATE jobs SET pending_report = NULL WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): bool {
        return $this->db->execute('UPDATE jobs SET status = ? WHERE id = ? RETURNING id', [$status, $id])->fetchRow() !== null;
    }

    public function setNext(int $id, ?DateTimeImmutable $next): bool {
        return $this->db->execute('UPDATE jobs SET next_run_at = to_timestamp(?) WHERE id = ? RETURNING id', [$next?->getTimestamp(), $id])->fetchRow() !== null;
    }

    public function resetRunning(): int {
        $count = 0;
        foreach ($this->db->query('UPDATE jobs SET running = false WHERE running RETURNING id') as $row) {
            $count++;
        }
        return $count;
    }

    public function bindHook(int $id, ?string $secret): bool {
        return $this->db->execute("UPDATE jobs SET hook_secret = ? WHERE id = ? AND schedule = 'event' RETURNING id", [$secret, $id])->fetchRow() !== null;
    }

    public function claimEvent(int $id): bool {
        return $this->db->execute(
            "UPDATE jobs SET running = true, last_run_at = now(), runs = runs + 1
             WHERE id = ? AND schedule = 'event' AND status = 'active' AND NOT running RETURNING id",
            [$id],
        )->fetchRow() !== null;
    }

    public function release(int $id): void {
        $this->db->execute('UPDATE jobs SET running = false, runs = greatest(runs - 1, 0) WHERE id = ? AND running', [$id]);
    }

    public function recordDelivery(int $jobId, string $deliveryId): bool {
        // Deliveries are kept 90 days: a GitHub delivery is known by its body, and a replay must stay a repeat.
        $this->db->execute("DELETE FROM hook_deliveries WHERE job_id = ? AND received_at < now() - interval '90 days'", [$jobId]);
        return $this->db->execute(
            'INSERT INTO hook_deliveries (job_id, delivery_id) VALUES (?, ?) ON CONFLICT DO NOTHING RETURNING job_id',
            [$jobId, $deliveryId],
        )->fetchRow() !== null;
    }

    public function deliveriesSince(int $jobId, DateTimeImmutable $since): int {
        $row = $this->db->execute('SELECT count(*) AS n FROM hook_deliveries WHERE job_id = ? AND received_at >= to_timestamp(?)', [$jobId, $since->getTimestamp()])->fetchRow();
        return isset($row['n']) && is_int($row['n']) ? $row['n'] : 0;
    }

    /** @param array<string, mixed> $row */
    private static function job(array $row): Job {
        $int = static fn(string $k): int => isset($row[$k]) && is_int($row[$k]) ? $row[$k] : throw new UnexpectedValueException('Malformed job row: ' . $k);
        $str = static fn(string $k): string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : throw new UnexpectedValueException('Malformed job row: ' . $k);
        $opt = static fn(string $k): ?string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : null;
        $next = isset($row['next']) && is_int($row['next']) ? new DateTimeImmutable('@' . $row['next']) : null;
        return new Job($int('id'), $int('principal_id'), $str('channel'), $str('chat_id'), $str('title'), $str('prompt'), $str('schedule'), $next, $int('runs'), $opt('last_result'), $opt('pending_report'), $str('status'), $opt('hook_secret'));
    }
}
