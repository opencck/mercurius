<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\Approval;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\Grant;

/**
 * Approvals and saved grants.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты
 */
interface ApprovalRepository {
    public function create(int $runId, string $callId, int $principalId, string $tool, string $summary, ?string $grantKey, DateTimeImmutable $expiresAt): Approval;

    public function find(int $id): ?Approval;

    public function forCall(int $runId, string $callId): ?Approval;

    /** Decides a pending approval atomically; null when it was already decided, or its time is up and the decision is not Expired. */
    public function decide(int $id, ApprovalStatus $status, ?string $reason, ?int $decidedBy): ?Approval;

    /** @return list<Approval> pending approvals whose time is up */
    public function due(DateTimeImmutable $now): array;

    /** The approval a run waits for, if any. */
    public function pendingForRun(int $runId): ?Approval;

    public function setCard(int $id, string $chatId, string $messageId): void;

    /**
     * How many of the latest decisions on exactly this action, asked by this participant since $since, were refusals
     * in a row (an approval ends the streak).
     */
    public function deniedInARow(int $principalId, string $summary, DateTimeImmutable $since): int;

    public function hasGrant(int $principalId, string $key): bool;

    public function addGrant(int $principalId, string $key): void;

    /** @return list<Grant> active grants of one participant, or of everyone when null */
    public function grants(?int $principalId = null): array;

    public function revokeGrant(int $id): ?Grant;
}
