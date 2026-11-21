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
        return $this->approvals[$id] = new Approval($a->id, $a->runId, $a->callId, $a->principalId, $a->tool, $a->summary, $a->grantKey, $status, $reason, $a->cardChatId, $a->cardMessageId, $a->createdAt, $a->wideKey);
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
        $this->approvals[$id] = new Approval($a->id, $a->runId, $a->callId, $a->principalId, $a->tool, $a->summary, $a->grantKey, $a->status, $a->reason, $chatId, $messageId, $a->createdAt, $a->wideKey);
    }

    /** Test helper: make an approval due now. */
    public function expireNow(int $id): void {
        $this->expires[$id] = new DateTimeImmutable('-1 second');
    }

    public function setWideKey(int $id, string $wideKey): void {
        $a = $this->approvals[$id];
        $this->approvals[$id] = new Approval($a->id, $a->runId, $a->callId, $a->principalId, $a->tool, $a->summary, $a->grantKey, $a->status, $a->reason, $a->cardChatId, $a->cardMessageId, $a->createdAt, $wideKey);
    }

    public function hasGrant(int $principalId, string $key): bool {
        foreach ($this->grants as $grant) {
            if ($grant->principalId === $principalId && $grant->key === $key && $grant->conversationId === null) {
                return true;
            }
        }
        return false;
    }

    public function addGrant(int $principalId, string $key, ?int $conversationId = null, bool $outside = false, ?string $label = null): void {
        foreach ($this->grants as $id => $g) {
            if ($g->principalId === $principalId && $g->key === $key && $g->conversationId === $conversationId) {
                $this->grants[$id] = new Grant($g->id, $principalId, $key, $conversationId, $g->outside || $outside, $g->uses, $g->label ?? $label, $g->createdAt);
                return;
            }
        }
        $grant = new Grant($this->nextGrant++, $principalId, $key, $conversationId, $outside, 0, $label, new DateTimeImmutable());
        $this->grants[$grant->id] = $grant;
    }

    /** Test helper: a grant given so many days ago. */
    public function ageGrant(int $id, int $days): void {
        $g = $this->grants[$id];
        $this->grants[$id] = new Grant($g->id, $g->principalId, $g->key, $g->conversationId, $g->outside, $g->uses, $g->label, new DateTimeImmutable(sprintf('-%d days', $days)));
    }

    public function grantFor(int $principalId, array $keys, ?int $conversationId, bool $outside): ?Grant {
        $found = null;
        foreach ($this->grants as $g) {
            if ($g->principalId !== $principalId || !in_array($g->key, $keys, true) || ($g->conversationId !== null && $g->conversationId !== $conversationId) || ($outside && !$g->outside)) {
                continue;
            }
            if ($found === null || ($g->conversationId === null && $found->conversationId !== null)) {
                $found = $g;
            }
        }
        return $found;
    }

    public function useGrant(int $id): void {
        $g = $this->grants[$id] ?? null;
        if ($g !== null) {
            $this->grants[$id] = new Grant($g->id, $g->principalId, $g->key, $g->conversationId, $g->outside, $g->uses + 1, $g->label, $g->createdAt);
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

    public function revokeGrants(string $prefix): int {
        $count = 0;
        foreach ($this->grants as $id => $g) {
            if (str_starts_with($g->key, $prefix)) {
                unset($this->grants[$id]);
                $count++;
            }
        }
        return $count;
    }

    /** @var array<int, \OpenCCK\Domain\Agent\GrantRequest> */
    private array $requests = [];

    public function noteNeed(string $area, int $principalId, string $key, string $label): void {
        $pending = $this->needs($area);
        if ($pending === null) {
            $id = count($this->requests) + 1;
            $this->requests[$id] = new \OpenCCK\Domain\Agent\GrantRequest($id, $area, $principalId, [['key' => $key, 'label' => $label]], 'pending');
            return;
        }
        if (!in_array($key, array_column($pending->items, 'key'), true)) {
            $this->requests[$pending->id] = new \OpenCCK\Domain\Agent\GrantRequest($pending->id, $area, $pending->principalId, [...$pending->items, ['key' => $key, 'label' => $label]], 'pending', $pending->cardChatId, $pending->cardMessageId);
        }
    }

    public function needs(string $area): ?\OpenCCK\Domain\Agent\GrantRequest {
        foreach ($this->requests as $request) {
            if ($request->area === $area && $request->status === 'pending') {
                return $request;
            }
        }
        return null;
    }

    public function findNeeds(int $id): ?\OpenCCK\Domain\Agent\GrantRequest {
        return $this->requests[$id] ?? null;
    }

    public function setNeedsCard(int $id, string $chatId, string $messageId): void {
        $r = $this->requests[$id];
        $this->requests[$id] = new \OpenCCK\Domain\Agent\GrantRequest($r->id, $r->area, $r->principalId, $r->items, $r->status, $chatId, $messageId);
    }

    public function closeNeeds(int $id, string $status): ?\OpenCCK\Domain\Agent\GrantRequest {
        $r = $this->requests[$id] ?? null;
        if ($r === null || $r->status !== 'pending') {
            return null;
        }
        return $this->requests[$id] = new \OpenCCK\Domain\Agent\GrantRequest($r->id, $r->area, $r->principalId, $r->items, $status, $r->cardChatId, $r->cardMessageId);
    }
}
