<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\Approval;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\Grant;
use OpenCCK\Domain\Repository\ApprovalRepository;

final class InMemoryApprovalRepository implements ApprovalRepository {
    /** @var array<int, Approval> */
    public array $approvals = [];
    /** @var array<int, DateTimeImmutable> */
    private array $expires = [];
    /** @var array<int, Grant> */
    private array $grants = [];
    /** @var array<int, ?int> who decided each approval; null — decided by the agent itself */
    private array $deciders = [];
    private int $next = 1;
    private int $nextGrant = 1;

    public function create(int $runId, string $callId, int $principalId, string $tool, string $summary, ?string $grantKey, DateTimeImmutable $expiresAt): Approval {
        $approval = new Approval($this->next++, $runId, $callId, $principalId, $tool, $summary, $grantKey, ApprovalStatus::Pending, createdAt: new \DateTimeImmutable());
        $this->expires[$approval->id] = $expiresAt;
        return $this->approvals[$approval->id] = $approval;
    }

    public function find(int $id): ?Approval {
        return $this->approvals[$id] ?? null;
    }

    public function forCall(int $runId, string $callId): ?Approval {
        foreach ($this->approvals as $approval) {
            if ($approval->runId === $runId && $approval->callId === $callId) {
                return $approval;
            }
        }
        return null;
    }

    public function deniedInARow(int $principalId, string $summary, DateTimeImmutable $since): int {
        $denied = 0;
        foreach (array_reverse($this->approvals) as $a) {
            if ($a->principalId !== $principalId || $a->summary !== $summary || !in_array($a->status, [ApprovalStatus::Approved, ApprovalStatus::Denied], true)) {
                continue;
            }
            if ($a->status === ApprovalStatus::Denied && ($this->deciders[$a->id] ?? null) === null) {
                continue;
            }
            if ($a->status !== ApprovalStatus::Denied) {
                break;
            }
            $denied++;
        }
        return $denied;
    }

    public function decide(int $id, ApprovalStatus $status, ?string $reason, ?int $decidedBy): ?Approval {
        $a = $this->approvals[$id] ?? null;
        if ($a === null || $a->status !== ApprovalStatus::Pending) {
            return null;
        }
        if ($status !== ApprovalStatus::Expired && $this->expires[$id] <= new DateTimeImmutable()) {
            return null;
        }
        $this->deciders[$id] = $decidedBy;
        return $this->approvals[$id] = new Approval($a->id, $a->runId, $a->callId, $a->principalId, $a->tool, $a->summary, $a->grantKey, $status, $reason, $a->cardChatId, $a->cardMessageId, $a->createdAt);
    }

    public function due(DateTimeImmutable $now): array {
        $due = [];
        foreach ($this->approvals as $id => $approval) {
            if ($approval->status === ApprovalStatus::Pending && $this->expires[$id] <= $now) {
                $due[] = $approval;
            }
        }
        return $due;
    }

    public function pendingForRun(int $runId): ?Approval {
        foreach (array_reverse($this->approvals) as $approval) {
            if ($approval->status === ApprovalStatus::Pending && $approval->runId === $runId) {
                return $approval;
            }
        }
        return null;
    }

    public function setCard(int $id, string $chatId, string $messageId): void {
        $a = $this->approvals[$id];
        $this->approvals[$id] = new Approval($a->id, $a->runId, $a->callId, $a->principalId, $a->tool, $a->summary, $a->grantKey, $a->status, $a->reason, $chatId, $messageId, $a->createdAt);
    }

    /** Test helper: make an approval due now. */
    public function expireNow(int $id): void {
        $this->expires[$id] = new DateTimeImmutable('-1 second');
    }

    public function hasGrant(int $principalId, string $key): bool {
        foreach ($this->grants as $grant) {
            if ($grant->principalId === $principalId && $grant->key === $key) {
                return true;
            }
        }
        return false;
    }

    public function addGrant(int $principalId, string $key): void {
        if (!$this->hasGrant($principalId, $key)) {
            $grant = new Grant($this->nextGrant++, $principalId, $key);
            $this->grants[$grant->id] = $grant;
        }
    }

    public function grants(?int $principalId = null): array {
        return array_values(array_filter($this->grants, static fn(Grant $g): bool => $principalId === null || $g->principalId === $principalId));
    }

    public function revokeGrant(int $id): ?Grant {
        $grant = $this->grants[$id] ?? null;
        unset($this->grants[$id]);
        return $grant;
    }
}
