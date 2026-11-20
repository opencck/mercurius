<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Entity;

use InvalidArgumentException;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Role;
use PHPUnit\Framework\TestCase;

/**
 * A role is one object per name: roles compare with ===, the catalog gives them titles, order and powers.
 *
 * spec: brief/participants.md#свои-роли-решение-владельца-2026-11-19
 */
final class RoleTest extends TestCase {
    protected function tearDown(): void {
        Role::reset();
    }

    public function testOneObjectPerNameAndTheStartingSet(): void {
        self::assertSame(Role::of('admin'), Role::admin());
        self::assertTrue(Role::owner()->may(Power::Publish), 'the owner has every power');
        self::assertTrue(Role::user()->may(Power::Skills));
        self::assertFalse(Role::user()->may(Power::Admit));
        self::assertFalse(Role::visitor()->may(Power::Tools));
        self::assertSame(['admin', 'user', 'visitor'], array_map(static fn(Role $r): string => $r->value, Role::assignable()));
    }

    public function testTheOwnerDefinesAnyRoleAndItsPlace(): void {
        $trader = Role::define('trader', 'Трейдер', 15, [Power::Tools, Power::Reports]);

        self::assertSame($trader, Role::from('trader'));
        self::assertSame('Трейдер', $trader->title());
        self::assertTrue($trader->may(Power::Reports));
        self::assertFalse($trader->may(Power::Secrets));
        self::assertSame(['admin', 'trader', 'user', 'visitor'], array_map(static fn(Role $r): string => $r->value, Role::assignable()));
        self::assertNull(Role::tryFrom('Bad Name'));
    }

    public function testABadNameIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);
        Role::of('Роль');
    }
}
