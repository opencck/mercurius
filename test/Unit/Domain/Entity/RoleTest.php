<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Entity;

use InvalidArgumentException;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Role;
use PHPUnit\Framework\TestCase;

/**
 * A role is one object per name: roles compare with ===, the catalog gives them titles, order and permissions.
 *
 * spec: brief/participants.md#свои-роли
 */
final class RoleTest extends TestCase {
    protected function tearDown(): void {
        Role::reset();
    }

    public function testOneObjectPerNameAndTheStartingSet(): void {
        self::assertSame(Role::of('admin'), Role::admin());
        self::assertTrue(Role::owner()->may(Permission::Publish), 'the owner has every permission');
        self::assertTrue(Role::user()->may(Permission::Skills));
        self::assertFalse(Role::user()->may(Permission::Admit));
        self::assertFalse(Role::visitor()->may(Permission::Tools));
        self::assertSame(['admin', 'user', 'visitor'], array_map(static fn(Role $r): string => $r->value, Role::assignable()));
    }

    public function testTheOwnerDefinesAnyRoleAndItsPlace(): void {
        $trader = Role::define('trader', 'Трейдер', 15, [Permission::Tools, Permission::Reports]);

        self::assertSame($trader, Role::from('trader'));
        self::assertSame('Трейдер', $trader->title());
        self::assertTrue($trader->may(Permission::Reports));
        self::assertFalse($trader->may(Permission::Secrets));
        self::assertSame(['admin', 'trader', 'user', 'visitor'], array_map(static fn(Role $r): string => $r->value, Role::assignable()));
        self::assertNull(Role::tryFrom('Bad Name'));
    }

    public function testABadNameIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);
        Role::of('Роль');
    }
}
