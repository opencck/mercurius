<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\Approval;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\Grant;
use OpenCCK\Domain\Agent\GrantRequest;

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

    /** A wider key the card of the approval offers. */
    public function setWideKey(int $id, string $wideKey): void;

    /** Whether the participant has this grant for good (of any kind). */
    public function hasGrant(int $principalId, string $key): bool;

    /**
     * Saves a grant: for good, or for one conversation; $outside — it applies after outside data too. Saving again
     * widens an existing grant (adds $outside), never narrows it.
     *
     * spec: brief/agent-loop.md#разрешения-навсегда
     */
    public function addGrant(int $principalId, string $key, ?int $conversationId = null, bool $outside = false, ?string $label = null): void;

    /**
     * The grant that lets one of the keys run: for good or for this conversation; in a run that read outside data
     * ($outside) only a grant given for that.
     *
     * @param list<string> $keys
     */
    public function grantFor(int $principalId, array $keys, ?int $conversationId, bool $outside): ?Grant;

    /** A call ran by the grant. */
    public function useGrant(int $id): void;

    /** @return list<Grant> active grants, oldest first; of one participant or of everyone */
    public function grants(?int $principalId = null): array;

    public function revokeGrant(int $id): ?Grant;

    /** Revokes the grants whose key starts with the prefix; how many. */
    public function revokeGrants(string $prefix): int;

    /**
     * A run with nobody to ask lacked a grant: it joins the pending list of its area (one list per area).
     *
     * spec: brief/scheduler.md#разрешения-задания
     */
    public function noteNeed(string $area, int $principalId, string $key, string $label): void;

    /** The pending list of an area; null — nothing is lacking. */
    public function needs(string $area): ?GrantRequest;

    public function findNeeds(int $id): ?GrantRequest;

    public function setNeedsCard(int $id, string $chatId, string $messageId): void;

    /** Decides a pending list once; null when it was decided already. */
    public function closeNeeds(int $id, string $status): ?GrantRequest;
}
