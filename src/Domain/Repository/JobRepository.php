<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Job\Job;

/**
 * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов
 */
interface JobRepository {
    /** @param string|null $origin the outside data the run that sets the job up had read */
    public function create(int $principalId, string $channel, string $chatId, string $title, string $prompt, string $schedule, ?DateTimeImmutable $nextRunAt, ?string $origin = null, string $threadId = ''): Job;

    /** The owner trusts the source of the job, or no longer does. */
    public function setTrusted(int $id, bool $trusted): bool;

    /** spec: brief/scheduler.md#как-сделано-задания-по-событию-и-проекты — the filter of webhook events of a job */
    public function setHookFilter(int $id, string $filter): bool;

    /** spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — the model role of a job's runs: '' or 'cheap' */
    public function setModelRole(int $id, string $role): bool;

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

    /** Sets or clears (null) the address the job watches; its fingerprint starts anew. */
    public function setWatch(int $id, ?string $url): bool;

    /** Remembers the fingerprint of what the watched address gave. */
    public function setWatchHash(int $id, string $hash): void;

    /** @return list<Job> jobs with a report held back */
    public function pendingReports(): array;

    public function clearPending(int $id): void;

    /**
     * A report that did not go out waits to be sent again.
     *
     * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — a failed delivery is retried and names its reason
     */
    public function holdReport(int $id, string $report): void;

    /** The last result of a job gets a line: why its report could not be delivered. */
    public function noteResult(int $id, string $line): void;

    public function setStatus(int $id, string $status): bool;

    /** Moves the next run: resuming counts it from now, a run now puts it at now. */
    public function setNext(int $id, ?DateTimeImmutable $next): bool;

    /** A new schedule and its next run; false when there is no such job. */
    public function setSchedule(int $id, string $schedule, ?DateTimeImmutable $next): bool;

    /** After a restart nothing runs. */
    public function resetRunning(): int;

    /** Names the vault secret that signs the webhook of a job by event; null stops taking the webhook. */
    public function bindHook(int $id, ?string $secret): bool;

    /** Claims an active job by event that is not running: marks it running and counts the run. */
    public function claimEvent(int $id): bool;

    /** Running no more, without a result: a claimed event turned out to be a repeat. */
    public function release(int $id): void;

    /** Records a webhook delivery; false — this delivery was taken before. */
    public function recordDelivery(int $jobId, string $deliveryId): bool;

    public function deliveriesSince(int $jobId, DateTimeImmutable $since): int;
}
