<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;

/**
 * spec: BRIEF.md#запуск-и-цикл, BRIEF.md#лимиты, BRIEF.md#устойчивость
 */
interface RunRepository {
    /** A new running run; null when the conversation already has an active one. */
    public function start(int $conversationId, int $principalId, string $model, RunLimits $limits, ?int $parentRunId = null): ?Run;

    public function find(int $id): ?Run;

    public function active(int $conversationId): ?Run;

    /** @return list<Run> runs left in the running status, oldest first */
    public function unfinished(): array;

    /** Reserves tokens atomically; false when the budget would be exceeded. */
    public function reserve(int $runId, int $tokens): bool;

    /** Replaces a reservation with the actual spend and counts one turn. */
    public function settle(int $runId, int $reserved, int $actual, int $promptAnchor): void;

    public function addActiveTime(int $runId, int $ms): void;

    public function markWarned(int $runId): void;

    public function finish(int $runId, RunStatus $status, ?StopReason $reason): void;

    /** Pauses a run for an approval or puts it back to running; the stop reason is untouched. */
    public function setStatus(int $runId, RunStatus $status): void;

    /** Moves the run from one status to another atomically; null when it was not in the expected status. */
    public function transition(int $runId, RunStatus $from, RunStatus $to): ?Run;

    /** @return list<Run> runs paused for the owner's decision */
    public function waitingApproval(): array;
}
