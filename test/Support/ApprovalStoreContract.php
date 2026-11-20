<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Repository\ApprovalRepository;

use function Amp\async;
use function Amp\Future\await;

/**
 * Behaviour of the approval store, run on the in-memory fake locally and on PostgreSQL on the server.
 */
abstract class ApprovalStoreContract extends AsyncTestCase {
    protected ApprovalRepository $approvals;
    protected int $runId;
    protected int $principalId;
    protected int $conversationId;

    private function create(string $callId = 'c1', string $expires = '+30 minutes'): int {
        return $this->approvals->create($this->runId, $callId, $this->principalId, 'run_command', 'git push', 'git-push:origin', new DateTimeImmutable($expires))->id;
    }

    public function testAnApprovalKnowsWhenItsCardWasAskedFor(): void {
        // spec: brief/operations.md#трассировка-и-метрики — the approval step lasts from the card to the decision
        $before = time() - 5;
        $decided = $this->approvals->decide($this->create('c1'), ApprovalStatus::Approved, null, null);

        $created = $decided?->createdAt;
        self::assertNotNull($created);
        self::assertGreaterThanOrEqual($before, $created->getTimestamp());
        self::assertLessThanOrEqual(time() + 5, $created->getTimestamp());
    }

    public function testRefusalsInARowAreCountedUntilAnApproval(): void {
        $since = new DateTimeImmutable('-1 day');
        $first = $this->create('c1');
        $this->approvals->decide($first, ApprovalStatus::Approved, null, null);
        foreach (['c2', 'c3'] as $call) {
            $this->approvals->decide($this->create($call), ApprovalStatus::Denied, 'нет', $this->principalId);
        }
        $this->create('c4');

        self::assertSame(2, $this->approvals->deniedInARow($this->principalId, 'git push', $since), 'a pending one and the approval before do not count');
        self::assertSame(0, $this->approvals->deniedInARow($this->principalId, 'git push --force', $since), 'another action is another streak');
    }

    public function testOnlyRefusalsOfAPersonAreCounted(): void {
        $since = new DateTimeImmutable('-1 day');
        $this->approvals->decide($this->create('c1'), ApprovalStatus::Denied, 'нет', $this->principalId);
        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — a card that did not reach the owner is not their refusal
        foreach (['c2', 'c3', 'c4'] as $call) {
            $this->approvals->decide($this->create($call), ApprovalStatus::Denied, 'карточка не дошла', null);
        }
        self::assertSame(1, $this->approvals->deniedInARow($this->principalId, 'git push', $since), 'automatic refusals neither count nor break the streak');
    }

    public function testCreatedApprovalIsPending(): void {
        $id = $this->create();
        $approval = $this->approvals->find($id);

        self::assertNotNull($approval);
        self::assertSame(ApprovalStatus::Pending, $approval->status);
        self::assertSame('git-push:origin', $approval->grantKey);
        self::assertSame($id, $this->approvals->forCall($this->runId, 'c1')?->id);
        self::assertSame($id, $this->approvals->pendingForRun($this->runId)?->id);
    }

    public function testDecisionIsTakenOnce(): void {
        $id = $this->create();
        $results = await([
            async(fn() => $this->approvals->decide($id, ApprovalStatus::Approved, null, $this->principalId)),
            async(fn() => $this->approvals->decide($id, ApprovalStatus::Denied, 'нет', $this->principalId)),
        ]);

        self::assertCount(1, array_filter($results, static fn($r) => $r !== null));
        self::assertNull($this->approvals->pendingForRun($this->runId));
    }

    public function testExpiredCardCannotBeApprovedOnlyExpired(): void {
        $id = $this->create('c1', '-1 minute');

        self::assertNull($this->approvals->decide($id, ApprovalStatus::Approved, null, $this->principalId));
        self::assertNull($this->approvals->decide($id, ApprovalStatus::Denied, 'нет', $this->principalId));
        self::assertSame(ApprovalStatus::Expired, $this->approvals->decide($id, ApprovalStatus::Expired, null, null)?->status);
    }

    public function testDueListsOnlyExpiredPending(): void {
        $old = $this->create('c1', '-1 minute');
        $this->create('c2', '+30 minutes');

        self::assertSame([$old], array_map(static fn($a) => $a->id, $this->approvals->due(new DateTimeImmutable())));
    }

    public function testCardIsKept(): void {
        $id = $this->create();
        $this->approvals->setCard($id, '42', '100');

        self::assertSame('100', $this->approvals->find($id)?->cardMessageId);
    }

    public function testGrantsAreAddedOnceAndRevoked(): void {
        self::assertFalse($this->approvals->hasGrant($this->principalId, 'git-push:origin'));
        $this->approvals->addGrant($this->principalId, 'git-push:origin');
        $this->approvals->addGrant($this->principalId, 'git-push:origin');

        self::assertTrue($this->approvals->hasGrant($this->principalId, 'git-push:origin'));
        $grants = $this->approvals->grants($this->principalId);
        self::assertCount(1, $grants);
        self::assertSame('git-push:origin', $this->approvals->revokeGrant($grants[0]->id)?->key);
        self::assertFalse($this->approvals->hasGrant($this->principalId, 'git-push:origin'));
        self::assertNull($this->approvals->revokeGrant($grants[0]->id));
    }
}
