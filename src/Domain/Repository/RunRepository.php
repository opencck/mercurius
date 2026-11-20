<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;

/**
 * spec: brief/agent-loop.md#права-участника, BRIEF.md#лимиты, BRIEF.md#устойчивость
 */
interface RunRepository {
    /** A new running run; null when the conversation already has an active one. */
    /** @param ?string $outside outside data the run starts with (a group chat); null — none */
    public function start(int $conversationId, int $principalId, string $model, RunLimits $limits, ?int $parentRunId = null, ?string $outside = null): ?Run;

    /** Notes that outside data came into the run from $source; each source is kept once. */
    public function markOutside(int $runId, string $source): void;

    public function find(int $id): ?Run;

    public function active(int $conversationId): ?Run;

    /** @return list<Run> runs left in the running status, oldest first */
    public function unfinished(): array;

    /** Reserves tokens atomically; false when the budget would be exceeded. */
    public function reserve(int $runId, int $tokens): bool;

    /** Replaces a reservation with the actual spend and counts one turn. */
    public function settle(int $runId, int $reserved, int $actual, int $promptAnchor): void;

    /** Spend of an auxiliary call (compaction): tokens count, the turn does not; the prompt anchor is reset. */
    public function settleAuxiliary(int $runId, int $reserved, int $actual): void;

    public function addActiveTime(int $runId, int $ms): void;

    public function markWarned(int $runId): void;

    /** The run goes on with another model: kept, so a resumed run does not switch back or twice. */
    public function switchModel(int $runId, string $model): void;

    /** @param list<string> $history the signatures the loop guard saw: a resumed run goes on counting from them */
    public function saveLoopHistory(int $runId, array $history): void;

    public function finish(int $runId, RunStatus $status, ?StopReason $reason): void;

    /** Pauses a run for an approval or puts it back to running; the stop reason is untouched. */
    public function setStatus(int $runId, RunStatus $status): void;

    /** Moves the run from one status to another atomically; null when it was not in the expected status. */
    public function transition(int $runId, RunStatus $from, RunStatus $to): ?Run;

    /** Runs started under the run: workers, cascade retries, verifiers. */
    public function childCount(int $parentRunId): int;

    /** @return list<Run> runs paused for the owner's decision */
    public function waitingApproval(): array;
}
