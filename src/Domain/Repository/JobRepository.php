<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Job\Job;

/**
 * spec: BRIEF.md#планировщик-и-проекты
 */
interface JobRepository {
    public function create(int $principalId, string $channel, string $chatId, string $title, string $prompt, string $schedule, ?DateTimeImmutable $nextRunAt): Job;

    public function find(int $id): ?Job;

    /** @return list<Job> active and paused jobs of a participant (all of them for null), newest first */
    public function list(?int $principalId): array;

    /** @return list<Job> active jobs due by $now that are not running */
    public function due(DateTimeImmutable $now, int $limit): array;

    /**
     * Claims a due job: moves its next run (null — a one-off is done after this run) and marks it running, only if it
     * is still due and not running. False — someone else has it.
     */
    public function claim(int $id, DateTimeImmutable $now, ?DateTimeImmutable $next): bool;

    /** The run ended: its result, a report held for the quiet hours, running no more. */
    public function finish(int $id, string $result, ?string $pendingReport): void;

    /** @return list<Job> jobs with a report held back */
    public function pendingReports(): array;

    public function clearPending(int $id): void;

    public function setStatus(int $id, string $status): bool;

    /** After a restart nothing runs. */
    public function resetRunning(): int;
}
