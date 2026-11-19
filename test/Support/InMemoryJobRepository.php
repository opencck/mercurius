<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Repository\JobRepository;

final class InMemoryJobRepository implements JobRepository {
    /** @var array<int, Job> */
    public array $jobs = [];
    /** @var array<int, bool> */
    public array $running = [];
    /** @var array<int, array<string, DateTimeImmutable>> */
    public array $deliveries = [];

    public function create(int $principalId, string $channel, string $chatId, string $title, string $prompt, string $schedule, ?DateTimeImmutable $nextRunAt, ?string $origin = null, string $threadId = ''): Job {
        $id = count($this->jobs) + 1;
        return $this->jobs[$id] = new Job($id, $principalId, $channel, $chatId, $title, $prompt, $schedule, $nextRunAt, 0, null, null, Job::ACTIVE, origin: $origin, threadId: $threadId);
    }

    public function setHookFilter(int $id, string $filter): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = self::with($j, hookFilter: $filter);
        return true;
    }

    public function setModelRole(int $id, string $role): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = self::with($j, modelRole: $role);
        return true;
    }

    public function setTrusted(int $id, bool $trusted): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = self::with($j, trusted: $trusted);
        return true;
    }

    public function find(int $id): ?Job {
        return $this->jobs[$id] ?? null;
    }

    public function list(?int $principalId): array {
        return array_values(array_reverse(array_filter($this->jobs, static fn(Job $j): bool => $j->status !== Job::DONE && ($principalId === null || $j->principalId === $principalId))));
    }

    public function due(DateTimeImmutable $now, int $limit): array {
        return array_slice(array_values(array_filter($this->jobs, fn(Job $j): bool => $j->status === Job::ACTIVE && !($this->running[$j->id] ?? false) && $j->nextRunAt !== null && $j->nextRunAt <= $now)), 0, $limit);
    }

    public function claim(int $id, DateTimeImmutable $now, ?DateTimeImmutable $next): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null || ($this->running[$id] ?? false) || $j->status !== Job::ACTIVE || $j->nextRunAt === null || $j->nextRunAt > $now) {
            return false;
        }
        $this->running[$id] = true;
        $this->jobs[$id] = self::with($j, nextRunAt: $next, runs: $j->runs + 1);
        return true;
    }

    public function finish(int $id, string $result, ?string $pendingReport): void {
        $j = $this->jobs[$id];
        $this->running[$id] = false;
        $this->jobs[$id] = self::with($j, lastResult: $result, pendingReport: $pendingReport, status: $j->nextRunAt === null && !$j->byEvent() ? Job::DONE : $j->status);
    }

    public function pendingReports(): array {
        return array_values(array_filter($this->jobs, static fn(Job $j): bool => $j->pendingReport !== null));
    }

    public function clearPending(int $id): void {
        $this->jobs[$id] = self::with($this->jobs[$id], pendingReport: null);
    }

    public function holdReport(int $id, string $report): void {
        $this->jobs[$id] = self::with($this->jobs[$id], pendingReport: $report);
    }

    public function noteResult(int $id, string $line): void {
        $this->jobs[$id] = self::with($this->jobs[$id], lastResult: ($this->jobs[$id]->lastResult ?? '') . "\n" . $line);
    }

    public function setStatus(int $id, string $status): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = self::with($j, status: $status);
        return true;
    }

    public function setSchedule(int $id, string $schedule, ?DateTimeImmutable $next): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = new Job($j->id, $j->principalId, $j->channel, $j->chatId, $j->title, $j->prompt, $schedule, $next, $j->runs, $j->lastResult, $j->pendingReport, $j->status, $j->hookSecret, $j->watchUrl, $j->watchHash, $j->origin, $j->trusted, $j->threadId, $j->hookFilter, $j->modelRole);
        return true;
    }

    public function setNext(int $id, ?DateTimeImmutable $next): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = self::with($j, nextRunAt: $next);
        return true;
    }

    public function resetRunning(): int {
        $count = count(array_filter($this->running));
        $this->running = [];
        return $count;
    }

    public function setWatch(int $id, ?string $url): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = self::with($j, watchUrl: $url, watchHash: null);
        return true;
    }

    public function setWatchHash(int $id, string $hash): void {
        $j = $this->jobs[$id] ?? null;
        if ($j !== null) {
            $this->jobs[$id] = self::with($j, watchHash: $hash);
        }
    }

    public function bindHook(int $id, ?string $secret): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null || !$j->byEvent()) {
            return false;
        }
        $this->jobs[$id] = self::with($j, hookSecret: $secret, clearHook: $secret === null);
        return true;
    }

    public function claimEvent(int $id): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null || !$j->byEvent() || $j->status !== Job::ACTIVE || ($this->running[$id] ?? false)) {
            return false;
        }
        $this->running[$id] = true;
        $this->jobs[$id] = self::with($j, runs: $j->runs + 1);
        return true;
    }

    public function release(int $id): void {
        if ($this->running[$id] ?? false) {
            $this->running[$id] = false;
            $this->jobs[$id] = self::with($this->jobs[$id], runs: max(0, $this->jobs[$id]->runs - 1));
        }
    }

    public function recordDelivery(int $jobId, string $deliveryId): bool {
        if (isset($this->deliveries[$jobId][$deliveryId])) {
            return false;
        }
        $this->deliveries[$jobId][$deliveryId] = new DateTimeImmutable();
        return true;
    }

    public function deliveriesSince(int $jobId, DateTimeImmutable $since): int {
        return count(array_filter($this->deliveries[$jobId] ?? [], static fn(DateTimeImmutable $at): bool => $at >= $since));
    }

    /** false and '' keep a field as it is, so null can be set. */
    private static function with(
        Job $j,
        DateTimeImmutable|false|null $nextRunAt = false,
        ?int $runs = null,
        ?string $lastResult = null,
        ?string $pendingReport = '',
        ?string $status = null,
        ?string $hookSecret = null,
        bool $clearHook = false,
        string|false|null $watchUrl = false,
        string|false|null $watchHash = false,
        ?bool $trusted = null,
        ?string $hookFilter = null,
        ?string $modelRole = null,
    ): Job {
        return new Job(
            $j->id,
            $j->principalId,
            $j->channel,
            $j->chatId,
            $j->title,
            $j->prompt,
            $j->schedule,
            $nextRunAt === false ? $j->nextRunAt : $nextRunAt,
            $runs ?? $j->runs,
            $lastResult ?? $j->lastResult,
            $pendingReport === '' ? $j->pendingReport : $pendingReport,
            $status ?? $j->status,
            $clearHook ? null : ($hookSecret ?? $j->hookSecret),
            $watchUrl === false ? $j->watchUrl : $watchUrl,
            $watchHash === false ? $j->watchHash : $watchHash,
            $j->origin,
            $trusted ?? $j->trusted,
            $j->threadId,
            $hookFilter ?? $j->hookFilter,
            $modelRole ?? $j->modelRole,
        );
    }
}
