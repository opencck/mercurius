<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use OpenCCK\App\Service\AccessService;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use PHPUnit\Framework\TestCase;

final class AccessServiceTest extends TestCase {
    public function testOwnerIsCreatedOnFirstContact(): void {
        $access = new AccessService(new InMemoryMemberRepository(), 'telegram', '42');

        $user = $access->resolve('telegram', '42', 'Owner');

        self::assertNotNull($user);
        self::assertTrue($user->isOwner());
        self::assertSame($user, $access->resolve('telegram', '42', 'Owner'));
    }

    public function testStrangerIsRefused(): void {
        $access = new AccessService(new InMemoryMemberRepository(), 'telegram', '42');

        self::assertNull($access->resolve('telegram', '43', 'Stranger'));
    }

    public function testOwnerIdOnAnotherChannelIsNotOwner(): void {
        $access = new AccessService(new InMemoryMemberRepository(), 'telegram', '42');

        self::assertNull($access->resolve('slack', '42', null));
    }
}
