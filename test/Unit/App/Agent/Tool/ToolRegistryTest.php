<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use LogicException;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Test\Support\FakeTool;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase {
    /** @param list<string>|null $tools */
    private function policy(?array $tools): ParticipantPolicy {
        return new ParticipantPolicy($tools, new RunLimits(30, 1000, 900, 120), null, true);
    }

    public function testDefinitionsFollowPolicyInRegistrationOrder(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('b'));
        $registry->register(new FakeTool('a'));

        self::assertSame(['b', 'a'], $registry->names($this->policy(null)));
        self::assertSame(['a'], array_map(static fn($d) => $d->name, $registry->definitions($this->policy(['a']))));
        self::assertSame([], $registry->names($this->policy([])));
    }

    public function testDuplicateNameIsRejected(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('a'));

        $this->expectException(LogicException::class);
        $registry->register(new FakeTool('a'));
    }

    public function testGet(): void {
        $registry = new ToolRegistry();
        $tool = new FakeTool('a');
        $registry->register($tool);

        self::assertSame($tool, $registry->get('a'));
        self::assertNull($registry->get('b'));
    }
}
