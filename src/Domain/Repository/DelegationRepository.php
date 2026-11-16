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
     */
    public function create(int $conversationId, int $runId, int $principalId, string $callId, int $reserved, array $tasks): Delegation;

    public function find(int $id): ?Delegation;

    /** @return list<DelegationTask> by position */
    public function tasks(int $delegationId): array;

    public function runningCount(int $conversationId): int;

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

    public function markDelivered(int $id): void;

    /** Whether the run is a worker of a background delegation. */
    public function ownsRun(int $runId): bool;
}
