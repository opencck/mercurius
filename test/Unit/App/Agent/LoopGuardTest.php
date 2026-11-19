<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use OpenCCK\App\Agent\GuardVerdict;
use OpenCCK\App\Agent\LoopGuard;
use PHPUnit\Framework\TestCase;

final class LoopGuardTest extends TestCase {
    /** @return list<GuardVerdict> */
    private static function feed(LoopGuard $guard, string ...$signatures): array {
        return array_map($guard->observe(...), array_values($signatures));
    }

    public function testThirdIdenticalCallWarns(): void {
        self::assertSame([GuardVerdict::Ok, GuardVerdict::Ok, GuardVerdict::Warn], self::feed(new LoopGuard(), 'a', 'a', 'a'));
    }

    public function testFifthIdenticalCallHalts(): void {
        $verdicts = self::feed(new LoopGuard(), 'a', 'a', 'a', 'a', 'a');

        self::assertSame(GuardVerdict::Warn, $verdicts[3]);
        self::assertSame(GuardVerdict::Halt, $verdicts[4]);
    }

    public function testAGuardRebuiltFromItsHistoryGoesOnCounting(): void {
        $before = new LoopGuard();
        self::feed($before, 'b', 'a', 'a', 'a', 'a');

        $after = new LoopGuard($before->history());

        self::assertSame(['b', 'a', 'a', 'a', 'a'], $before->history());
        self::assertSame(GuardVerdict::Halt, self::feed($after, 'a')[0], 'a restart does not start the count over');
    }

    public function testDifferentCallResetsStreak(): void {
        self::assertSame(GuardVerdict::Ok, self::feed(new LoopGuard(), 'a', 'a', 'b', 'a', 'a')[4]);
    }

    public function testAlternatingPairIsDetected(): void {
        $verdicts = self::feed(new LoopGuard(), 'a', 'b', 'a', 'b', 'a', 'b', 'a', 'b', 'a', 'b');

        self::assertSame(GuardVerdict::Warn, $verdicts[5]);
        self::assertSame(GuardVerdict::Halt, $verdicts[9]);
    }

    public function testPeriodFiveIsNotDetected(): void {
        $sequence = [];
        for ($i = 0; $i < 6; $i++) {
            array_push($sequence, 'a', 'b', 'c', 'd', 'e');
        }
        $verdicts = self::feed(new LoopGuard(), ...$sequence);

        self::assertNotContains(GuardVerdict::Warn, $verdicts);
        self::assertNotContains(GuardVerdict::Halt, $verdicts);
    }

    public function testSignatureIgnoresKeyOrder(): void {
        self::assertSame(
            LoopGuard::signature('t', ['a' => 1, 'b' => 2], 'r'),
            LoopGuard::signature('t', ['b' => 2, 'a' => 1], 'r'),
        );
        self::assertNotSame(LoopGuard::signature('t', [], 'r1'), LoopGuard::signature('t', [], 'r2'));
    }
}
