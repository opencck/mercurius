<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\MemberRepository;

/**
 * Behaviour of the participant store, run on the in-memory fake locally and on PostgreSQL on the server.
 */
abstract class MemberStoreContract extends AsyncTestCase {
    protected MemberRepository $principals;
    /** A channel identity unique to the test run, so PostgreSQL rows of earlier runs do not interfere. */
    protected string $externalId;

    public function testVisitorIsCreatedPendingAndOnlyOnce(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, 'Гость');

        self::assertSame(Role::reader(), $visitor->role);
        self::assertSame(MemberStatus::Pending, $visitor->status);
        self::assertSame($visitor->id, $this->principals->createVisitor('telegram', $this->externalId, 'Гость')->id);
        self::assertSame($visitor->id, $this->principals->findByIdentity('telegram', $this->externalId)?->id);
        self::assertSame($this->externalId, $this->principals->externalId($visitor->id, 'telegram'));
        self::assertNull($this->principals->externalId($visitor->id, 'slack'));
    }

    public function testAnotherActivePersonBecomesAnOwner(): void {
        $owner = $this->principals->ensureOwner('telegram', 'owner-' . $this->externalId, 'Владелец');
        $next = $this->principals->createVisitor('telegram', $this->externalId, 'Второй');
        $this->principals->decideVisitor($next->id, Role::user(), MemberStatus::Active);
        $agent = $this->principals->createAgent('agent-' . $this->externalId, Role::user());
        $before = $this->principals->countActivePeople(Role::ownerRoles());
        try {
            self::assertFalse($this->principals->addOwner($agent->id), 'an agent does not own');
            self::assertFalse($this->principals->addOwner($owner->id), 'already an owner');

            self::assertTrue($this->principals->addOwner($next->id));

            self::assertTrue($this->principals->find($next->id)?->isOwner());
            self::assertTrue($this->principals->find($owner->id)?->isOwner(), 'nobody stops being an owner by it');
            self::assertSame($before + 1, $this->principals->countActivePeople(Role::ownerRoles()));
        } finally {
            // the store is shared with other tests on the server
            $this->principals->setRole($next->id, Role::user(), MemberStatus::Active);
        }
    }

    public function testTheLastOwnerKeepsTheRole(): void {
        $owner = $this->principals->ensureOwner('telegram', 'owner-' . $this->externalId, 'Владелец');
        $agent = $this->principals->createAgent('agent-' . $this->externalId, Role::user());
        if ($this->principals->countActivePeople(Role::ownerRoles()) > 1) {
            self::markTestSkipped('the shared store has other owners');
        }

        self::assertNull($this->principals->setRole($owner->id, Role::user(), MemberStatus::Active));
        self::assertNull($this->principals->setRole($agent->id, Role::admin(), MemberStatus::Active), 'an agent is never an owner');
        self::assertTrue($this->principals->find($owner->id)?->isOwner());
    }

    public function testVisitorIsDecidedOnce(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, 'Гость');

        $decided = $this->principals->decideVisitor($visitor->id, Role::user(), MemberStatus::Active);
        self::assertNotNull($decided);
        self::assertSame(Role::user(), $decided->role);
        self::assertSame(MemberStatus::Active, $decided->status);
        self::assertNull($this->principals->decideVisitor($visitor->id, Role::admin(), MemberStatus::Active));
        self::assertSame(Role::user(), $this->principals->find($visitor->id)?->role);
    }

    public function testRejectedVisitorStaysRejected(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, null);

        $this->principals->decideVisitor($visitor->id, Role::reader(), MemberStatus::Rejected);

        self::assertSame(MemberStatus::Rejected, $this->principals->findByIdentity('telegram', $this->externalId)?->status);
    }
}
