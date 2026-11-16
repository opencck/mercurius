<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\Delegation;
use OpenCCK\Domain\Agent\DelegationTask;

/**
 * Background delegations and their parts.
 *
 * spec: BRIEF.md#часть-3--фоновое-делегирование
 */
interface DelegationRepository {
    /**
     * A new running delegation with its parts, in one transaction.
     *
     * @param list<array{task: array<string, mixed>, share: int}> $tasks positions are 1, 2, …
     * @param positive-int $seconds time for the whole delegation
     */
    public function create(int $conversationId, int $runId, int $principalId, string $callId, int $reserved, array $tasks, int $seconds): Delegation;

    public function find(int $id): ?Delegation;

    /** @return list<DelegationTask> by position */
    public function tasks(int $delegationId): array;

    /** Running delegations of a participant, in every conversation. */
    public function runningCount(int $principalId): int;

    /** @return list<Delegation> running ones, oldest first */
    public function running(?int $conversationId = null): array;

    /** @return list<Delegation> done but not delivered, oldest first */
    public function undelivered(): array;

    /** Records the run of a part: attempt 1 is the first try, 2 — the cascade's retry. */
    public function setTaskRun(int $delegationId, int $position, int $attempt, int $runId): void;

    /** @param array<string, mixed> $report */
    public function finishTask(int $delegationId, int $position, array $report): void;

    /** Closes a running delegation; false when it was not running (another fiber closed it first). */
    public function finish(int $id, string $status, ?string $report): bool;

    /** /stop: kept so that a restart closes the delegation instead of continuing it. */
    public function requestStop(int $id): void;

    /** The report became the first message of this run. */
    public function markDelivered(int $id, int $runId): void;

    /** Whether the run began with a delegation's report. */
    public function deliveredInto(int $runId): bool;

    /** Whether the run is a worker of a background delegation. */
    public function ownsRun(int $runId): bool;
}
