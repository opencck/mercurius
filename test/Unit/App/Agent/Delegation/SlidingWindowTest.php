<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Delegation;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Delegation\SlidingWindow;

use function Amp\delay;

final class SlidingWindowTest extends AsyncTestCase {
    public function testAFreeSlotTakesTheNextJobAtOnce(): void {
        // spec: brief/orchestration.md#часть-1--синхронное-делегирование — a sliding window of three, not batches of three
        $jobs = [];
        foreach ([1 => 0.6, 2 => 0.2, 3 => 0.2, 4 => 0.2] as $key => $seconds) {
            $jobs[$key] = static function () use ($key, $seconds): string {
                delay($seconds);
                return 'part ' . $key;
            };
        }

        $started = microtime(true);
        $results = SlidingWindow::run($jobs, 3);
        $elapsed = microtime(true) - $started;

        self::assertSame([1 => 'part 1', 2 => 'part 2', 3 => 'part 3', 4 => 'part 4'], $results, 'in the order of the jobs');
        // batches: 0.6 for the first three, then 0.2 for the fourth; a window runs the fourth while the first goes on
        self::assertLessThan(0.75, $elapsed);
        self::assertGreaterThanOrEqual(0.6, $elapsed);
    }

    public function testNoMoreThanTheWidthAtOnce(): void {
        $running = 0;
        $most = 0;
        $jobs = [];
        for ($i = 0; $i < 7; $i++) {
            $jobs[] = static function () use (&$running, &$most): int {
                $running++;
                $most = max($most, $running);
                delay(0.05);
                $running--;
                return 1;
            };
        }

        self::assertCount(7, SlidingWindow::run($jobs, 3));
        self::assertSame(3, $most);
    }
}
