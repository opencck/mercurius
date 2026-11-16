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
 * spec: BRIEF.md#подтверждения-и-запреты
 */
interface ApprovalRepository {
    public function create(int $runId, string $callId, int $principalId, string $tool, string $summary, ?string $grantKey, DateTimeImmutable $expiresAt): Approval;

    public function find(int $id): ?Approval;

    public function forCall(int $runId, string $callId): ?Approval;

    /** Decides a pending approval atomically; null when it was already decided or expired. */
    public function decide(int $id, ApprovalStatus $status, ?string $reason, ?int $decidedBy): ?Approval;

    /** @return list<Approval> pending approvals whose time is up */
    public function due(DateTimeImmutable $now): array;

    public function pendingInConversation(int $conversationId): ?Approval;

    public function setCard(int $id, string $chatId, string $messageId): void;

    public function hasGrant(int $principalId, string $key): bool;

    public function addGrant(int $principalId, string $key): void;

    /** @return list<Grant> */
    public function grants(int $principalId): array;

    public function revokeGrant(int $id): ?Grant;
}
