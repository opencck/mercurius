<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;

/**
 * Behaviour of the participant store, run on the in-memory fake locally and on PostgreSQL on the server.
 */
abstract class PrincipalStoreContract extends AsyncTestCase {
    protected PrincipalRepository $principals;
    /** A channel identity unique to the test run, so PostgreSQL rows of earlier runs do not interfere. */
    protected string $externalId;

    public function testVisitorIsCreatedPendingAndOnlyOnce(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, 'Гость');

        self::assertSame(Role::visitor(), $visitor->role);
        self::assertSame(PrincipalStatus::Pending, $visitor->status);
        self::assertSame($visitor->id, $this->principals->createVisitor('telegram', $this->externalId, 'Гость')->id);
        self::assertSame($visitor->id, $this->principals->findByIdentity('telegram', $this->externalId)?->id);
        self::assertSame($this->externalId, $this->principals->externalId($visitor->id, 'telegram'));
        self::assertNull($this->principals->externalId($visitor->id, 'slack'));
    }

    public function testOwnershipPassesToAnActivePersonAndTheFormerOwnerBecomesAnAdmin(): void {
        $owner = $this->principals->ensureOwner('telegram', 'owner-' . $this->externalId, 'Владелец');
        $next = $this->principals->createVisitor('telegram', $this->externalId, 'Преемник');
        $this->principals->decideVisitor($next->id, Role::user(), PrincipalStatus::Active);
        $agent = $this->principals->createAgent('agent-' . $this->externalId, Role::user());
        try {
            self::assertFalse($this->principals->transferOwnership($next->id, $owner->id), 'only the owner passes it on');
            self::assertFalse($this->principals->transferOwnership($owner->id, $agent->id), 'an agent does not own');
            self::assertFalse($this->principals->transferOwnership($owner->id, $owner->id));

            self::assertTrue($this->principals->transferOwnership($owner->id, $next->id));

            self::assertSame($next->id, $this->principals->owner()?->id);
            self::assertSame(Role::admin(), $this->principals->find($owner->id)?->role);
            self::assertArrayHasKey($owner->id, $this->principals->formerOwners(), 'with the time ownership ended');
            self::assertArrayNotHasKey($next->id, $this->principals->formerOwners());
        } finally {
            // the store is shared with other tests on the server: ownership goes back
            $this->principals->transferOwnership($next->id, $owner->id);
        }
        self::assertSame($owner->id, $this->principals->owner()?->id);
    }

    public function testVisitorIsDecidedOnce(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, 'Гость');

        $decided = $this->principals->decideVisitor($visitor->id, Role::user(), PrincipalStatus::Active);
        self::assertNotNull($decided);
        self::assertSame(Role::user(), $decided->role);
        self::assertSame(PrincipalStatus::Active, $decided->status);
        self::assertNull($this->principals->decideVisitor($visitor->id, Role::admin(), PrincipalStatus::Active));
        self::assertSame(Role::user(), $this->principals->find($visitor->id)?->role);
    }

    public function testRejectedVisitorStaysRejected(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, null);

        $this->principals->decideVisitor($visitor->id, Role::visitor(), PrincipalStatus::Rejected);

        self::assertSame(PrincipalStatus::Rejected, $this->principals->findByIdentity('telegram', $this->externalId)?->status);
    }
}
