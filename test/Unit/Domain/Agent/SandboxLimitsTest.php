<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Agent;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\SandboxLimits;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/agent-loop.md#подтверждения-и-запреты — the address space of a command: 0 is no limit, the narrower of two set limits wins
 */
final class SandboxLimitsTest extends TestCase {
    public function testNoAddressSpaceLimitGivesWayToASetOne(): void {
        $none = new SandboxLimits(100, 10, 60, 0, 2);
        $set = new SandboxLimits(100, 10, 60, 4096, 2);

        self::assertSame(0, $none->min($none)->memoryBytes);
        self::assertSame(4096, $none->min($set)->memoryBytes);
        self::assertSame(4096, $set->min($none)->memoryBytes);
        self::assertSame(1024, $set->min(new SandboxLimits(100, 10, 60, 1024, 2))->memoryBytes);

        $this->expectException(InvalidArgumentException::class);
        new SandboxLimits(100, 10, 60, -1, 2);
    }
}
