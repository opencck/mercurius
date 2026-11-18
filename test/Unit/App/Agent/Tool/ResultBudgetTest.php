<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use OpenCCK\App\Agent\Tool\ResultBudget;
use PHPUnit\Framework\TestCase;

/**
 * One tool result takes at most 15% of the model's window, the results of a turn at most 30%, as in hermes; a small
 * model still gets a usable result, a big one keeps the fixed ceilings.
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18
 */
final class ResultBudgetTest extends TestCase {
    public function testTheCeilingsFollowTheWindow(): void {
        // ref: references/hermes-agent/tools/budget_config.py@e96221fa:L80-L113 — 15% and 30% of window * 4 chars,
        //      floors 8000 and 16000, caps 100000 and 200000
        $small = ResultBudget::forWindow(32_000);
        self::assertSame([19_200, 38_400], [$small->perResult, $small->perTurn]);
        $tiny = ResultBudget::forWindow(4_000);
        self::assertSame([8_000, 16_000], [$tiny->perResult, $tiny->perTurn], 'floors');
        $big = ResultBudget::forWindow(1_000_000);
        self::assertSame([100_000, 200_000], [$big->perResult, $big->perTurn], 'caps');
    }

    public function testTheLargestResultsOfATurnShrinkUntilItFits(): void {
        $budget = ResultBudget::forWindow(4_000);
        $contents = [str_repeat('a', 9_000), str_repeat('b', 3_000), str_repeat('c', 9_000), str_repeat('d', 2_000)];
        $shrunk = [];

        $fitted = $budget->fit($contents, static function (string $content, int $index) use (&$shrunk): string {
            $shrunk[] = $index;
            return substr($content, 0, 100);
        });

        self::assertLessThanOrEqual(16_000, array_sum(array_map('strlen', $fitted)));
        self::assertSame([0], $shrunk, 'the largest first, only as many as needed');
        self::assertSame($contents[1], $fitted[1], 'small ones stay whole');
    }
}
