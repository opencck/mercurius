<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Agent;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use PHPUnit\Framework\TestCase;

final class ParticipantPolicyTest extends TestCase {
    private function owner(): ParticipantPolicy {
        return new ParticipantPolicy(null, new RunLimits(30, 400_000, 900, 120), new SandboxLimits(5_000, 512, 600, 2_000, 4), true);
    }

    public function testOverridesCannotExceedOwner(): void {
        $base = new ParticipantPolicy([], new RunLimits(30, 400_000, 900, 120), null, false);
        $policy = ParticipantPolicy::fromArray(['max_turns' => 999, 'token_budget' => 1000], $base)->capTo($this->owner());

        self::assertSame(30, $policy->run->maxTurns);
        self::assertSame(1000, $policy->run->tokenBudget);
    }

    public function testToolsAreIntersectedWithOwner(): void {
        $owner = new ParticipantPolicy(['http_request'], new RunLimits(30, 400_000, 900, 120), null, true);
        $policy = (new ParticipantPolicy(['http_request', 'run_command'], new RunLimits(5, 10, 10, 10), null, true))->capTo($owner);

        self::assertSame(['http_request'], $policy->tools);
        self::assertTrue($policy->allowsTool('http_request'));
        self::assertFalse($policy->allowsTool('run_command'));
    }

    public function testAllToolsWhenNull(): void {
        self::assertTrue($this->owner()->allowsTool('anything'));
    }

    public function testApprovalNeedsBoth(): void {
        $owner = new ParticipantPolicy(null, new RunLimits(30, 400_000, 900, 120), null, false);
        $policy = (new ParticipantPolicy(null, new RunLimits(5, 10, 10, 10), null, true))->capTo($owner);

        self::assertFalse($policy->mayRequestApproval);
    }

    public function testTheOwnerInAGroupKeepsTheCardButNotTheTools(): void {
        $owner = new ParticipantPolicy(null, new RunLimits(50, 1000, 100, 100), null, true);
        $weakest = new ParticipantPolicy(['web_search'], new RunLimits(5, 10, 10, 10), null, false);

        $mine = $owner->narrowedBy($weakest, keepApproval: true);
        $theirs = $owner->narrowedBy($weakest);

        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the card goes to the owner with the exact call
        self::assertTrue($mine->mayRequestApproval);
        self::assertSame(['web_search'], $mine->tools);
        self::assertSame(5, $mine->run->maxTurns);
        self::assertFalse($theirs->mayRequestApproval);
        self::assertFalse((new ParticipantPolicy(null, new RunLimits(5, 10, 10, 10), null, false))->narrowedBy($weakest, keepApproval: true)->mayRequestApproval, 'kept, not given');
    }

    public function testSandboxLimitsAreCapped(): void {
        $base = new ParticipantPolicy([], new RunLimits(30, 400_000, 900, 120), new SandboxLimits(10_000, 10, 10, 10, 10), false);
        $policy = $base->capTo($this->owner());

        self::assertNotNull($policy->sandbox);
        self::assertSame(5_000, $policy->sandbox->diskBytes);
        self::assertSame(10, $policy->sandbox->processes);
    }

    public function testFromArrayRoundTrip(): void {
        $overrides = [
            'tools' => ['http_request'],
            'max_turns' => 5,
            'tool_timeout' => 60,
            'may_request_approval' => true,
            'sandbox' => ['disk_bytes' => 100, 'concurrent_commands' => 1],
        ];
        $base = $this->owner();
        $policy = ParticipantPolicy::fromArray($overrides, $base);

        self::assertSame(['http_request'], $policy->tools);
        self::assertSame(5, $policy->run->maxTurns);
        self::assertSame(400_000, $policy->run->tokenBudget);
        self::assertNotNull($policy->sandbox);
        self::assertSame(100, $policy->sandbox->diskBytes);
        self::assertSame(512, $policy->sandbox->processes);
        self::assertEquals($policy, ParticipantPolicy::fromArray($policy->toArray(), $base));
    }

    public function testUnknownKeyIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        ParticipantPolicy::fromArray(['max_turnz' => 5], $this->owner());
    }

    public function testNonPositiveLimitIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        ParticipantPolicy::fromArray(['max_turns' => 0], $this->owner());
    }
}
