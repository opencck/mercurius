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

        self::assertSame(Role::Visitor, $visitor->role);
        self::assertSame(PrincipalStatus::Pending, $visitor->status);
        self::assertSame($visitor->id, $this->principals->createVisitor('telegram', $this->externalId, 'Гость')->id);
        self::assertSame($visitor->id, $this->principals->findByIdentity('telegram', $this->externalId)?->id);
    }

    public function testVisitorIsDecidedOnce(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, 'Гость');

        $decided = $this->principals->decideVisitor($visitor->id, Role::User, PrincipalStatus::Active);
        self::assertNotNull($decided);
        self::assertSame(Role::User, $decided->role);
        self::assertSame(PrincipalStatus::Active, $decided->status);
        self::assertNull($this->principals->decideVisitor($visitor->id, Role::Admin, PrincipalStatus::Active));
        self::assertSame(Role::User, $this->principals->find($visitor->id)?->role);
    }

    public function testRejectedVisitorStaysRejected(): void {
        $visitor = $this->principals->createVisitor('telegram', $this->externalId, null);

        $this->principals->decideVisitor($visitor->id, Role::Visitor, PrincipalStatus::Rejected);

        self::assertSame(PrincipalStatus::Rejected, $this->principals->findByIdentity('telegram', $this->externalId)?->status);
    }
}
