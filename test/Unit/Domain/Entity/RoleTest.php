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
        self::assertTrue(Role::user()->may(Permission::Skills));
        self::assertFalse(Role::user()->may(Permission::Admit));
        self::assertFalse(Role::reader()->may(Permission::Tools));
        self::assertSame(['admin', 'user', 'reader'], array_map(static fn(Role $r): string => $r->value, Role::assignable()));
    }

    public function testAdminIsOwnerAndMayAll(): void {
        self::assertTrue(Role::admin()->isOwner());
        self::assertTrue(Role::admin()->may(Permission::Mcp), 'the owner permission gives every permission');
        self::assertSame(Permission::cases(), Role::admin()->permissions());
        self::assertFalse(Role::user()->isOwner());
        self::assertFalse(Role::user()->may(Permission::Mcp));
        self::assertSame([], Role::reader()->permissions());
    }

    public function testAnyRoleWithTheOwnerPermissionIsAnOwnerRole(): void {
        Role::define('chief', '', 5, [Permission::Owner]);

        self::assertTrue(Role::of('chief')->may(Permission::Publish));
        self::assertSame(['chief', 'admin'], Role::ownerRoles(), 'in the order of the roles');
    }

    public function testTheOwnerDefinesAnyRoleAndItsPlace(): void {
        $trader = Role::define('trader', 'Трейдер', 15, [Permission::Tools, Permission::Reports]);

        self::assertSame($trader, Role::from('trader'));
        self::assertSame('Трейдер', $trader->title());
        self::assertTrue($trader->may(Permission::Reports));
        self::assertFalse($trader->may(Permission::Secrets));
        self::assertSame(['admin', 'trader', 'user', 'reader'], array_map(static fn(Role $r): string => $r->value, Role::assignable()));
        self::assertNull(Role::tryFrom('Bad Name'));
    }

    public function testABadNameIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);
        Role::of('Роль');
    }
}
