<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use OpenCCK\Test\Support\RecallBench;
use PHPUnit\Framework\TestCase;

/**
 * The Russian set of questions to memory (test/Fixtures/memory-recall-ru.json): recall@5 does not fall, and HRR does
 * not make the order worse than words alone. Measured 2026-11-17: words 0.912 / MRR 0.858, with HRR 0.3 — 0.912 / 0.877;
 * 2026-11-19 with loanwords, same-root words and four rows on them: 0.974 / 0.893 and 0.974 / 0.912.
 */
final class RecallBenchmarkTest extends TestCase {
    public function testRecallOnRussianQuestions(): void {
        [$words, $wordsRank, $count] = RecallBench::run(0.0);
        [$hrr, $hrrRank] = RecallBench::run(0.3);

        self::assertGreaterThanOrEqual(30, $count);
        self::assertGreaterThanOrEqual(0.9, $hrr);
        self::assertGreaterThanOrEqual($words, $hrr);
        self::assertGreaterThanOrEqual($wordsRank, $hrrRank);
    }
}
