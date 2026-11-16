<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use OpenCCK\Memory\App\Audience;
use PHPUnit\Framework\TestCase;

final class AudienceTest extends TestCase {
    public function testOwnerInPrivateSeesOwnerMemory(): void {
        self::assertSame(['owner', 'chat:telegram:5'], Audience::scopes(true, 1, 'telegram', '5', true));
    }

    public function testOtherParticipantSeesOnlyTheirOwn(): void {
        self::assertSame(['principal:7', 'chat:telegram:9'], Audience::scopes(false, 7, 'telegram', '9', true));
    }

    public function testGroupSeesOnlyTheGroupEvenWithTheOwner(): void {
        self::assertSame(['chat:telegram:-100'], Audience::scopes(true, 1, 'telegram', '-100', false));
        self::assertSame(['chat:telegram:-100'], Audience::scopes(false, 7, 'telegram', '-100', false));
    }
}
