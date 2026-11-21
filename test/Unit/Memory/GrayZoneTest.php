<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use OpenCCK\Test\Support\PairBench;
use PHPUnit\Framework\TestCase;

/**
 * The gray zone of sleep on the pair sets: the pairs that need the model reach it, the unrelated ones mostly do not.
 * Measured 2026-11-21: ru 40/40 related, 8/12 unrelated sent; en 22/23, 3/7; zh 18/18, 3/5.
 *
 * spec: brief/memory.md#серая-зона
 */
final class GrayZoneTest extends TestCase {
    /** @var array<string, array{float, float}> the share of related pairs reached at least, of unrelated ones sent at most */
    private const FLOORS = ['ru' => [1.0, 0.7], 'en' => [0.95, 0.5], 'zh' => [1.0, 0.6]];

    public function testThePairsThatNeedTheModelReachIt(): void {
        foreach (self::FLOORS as $language => [$reached, $sent]) {
            $r = PairBench::measure(PairBench::file($language));
            self::assertGreaterThanOrEqual(20, $r['related'] + $r['unrelated'], $language);
            self::assertGreaterThanOrEqual($reached, (float) $r['reached'] / (float) $r['related'], $language . ': related pairs reached');
            self::assertLessThanOrEqual($sent, (float) $r['sent'] / (float) $r['unrelated'], $language . ': unrelated pairs sent');
        }
    }
}
