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

    public function create(int $principalId, string $channel, string $chatId, string $title, string $prompt, string $schedule, ?DateTimeImmutable $nextRunAt): Job {
        $id = count($this->jobs) + 1;
        return $this->jobs[$id] = new Job($id, $principalId, $channel, $chatId, $title, $prompt, $schedule, $nextRunAt, 0, null, null, Job::ACTIVE);
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
        $this->jobs[$id] = new Job($j->id, $j->principalId, $j->channel, $j->chatId, $j->title, $j->prompt, $j->schedule, $next, $j->runs + 1, $j->lastResult, $j->pendingReport, $j->status);
        return true;
    }

    public function finish(int $id, string $result, ?string $pendingReport): void {
        $j = $this->jobs[$id];
        $this->running[$id] = false;
        $this->jobs[$id] = new Job($j->id, $j->principalId, $j->channel, $j->chatId, $j->title, $j->prompt, $j->schedule, $j->nextRunAt, $j->runs, $result, $pendingReport, $j->nextRunAt === null ? Job::DONE : $j->status);
    }

    public function pendingReports(): array {
        return array_values(array_filter($this->jobs, static fn(Job $j): bool => $j->pendingReport !== null));
    }

    public function clearPending(int $id): void {
        $j = $this->jobs[$id];
        $this->jobs[$id] = new Job($j->id, $j->principalId, $j->channel, $j->chatId, $j->title, $j->prompt, $j->schedule, $j->nextRunAt, $j->runs, $j->lastResult, null, $j->status);
    }

    public function setStatus(int $id, string $status): bool {
        $j = $this->jobs[$id] ?? null;
        if ($j === null) {
            return false;
        }
        $this->jobs[$id] = new Job($j->id, $j->principalId, $j->channel, $j->chatId, $j->title, $j->prompt, $j->schedule, $j->nextRunAt, $j->runs, $j->lastResult, $j->pendingReport, $status);
        return true;
    }

    public function resetRunning(): int {
        $count = count(array_filter($this->running));
        $this->running = [];
        return $count;
    }
}
