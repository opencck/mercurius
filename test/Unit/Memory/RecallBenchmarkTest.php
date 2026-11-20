<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use Mercurius\Memory\App\Recall;
use OpenCCK\Test\Support\RecallBench;
use PHPUnit\Framework\TestCase;

/**
 * Memory search on the sets of test/Fixtures (Russian, English, Chinese, and all three in one memory): recall@5, the
 * facts given beside the expected ones and silence on questions memory must not answer do not fall below the floors.
 * The numbers of every measure are in the table of brief/memory.md; `php bin/recall-bench.php` prints them.
 *
 * spec: brief/memory.md#замер-поиска-на-трёх-языках — floors on recall, extra facts and silence, per language
 */
final class RecallBenchmarkTest extends TestCase {
    /** @var array<string, array{float, float, float}> recall@5 at least, extra facts per question at most, silence at least */
    private const FLOORS = [
        'ru' => [0.85, 0.3, 0.95],
        'en' => [0.85, 0.25, 0.85],
        'zh' => [0.9, 0.2, 0.85],
        'mixed' => [0.86, 0.27, 0.88],
    ];

    public function testEachLanguageHoldsItsFloors(): void {
        $files = array_map(RecallBench::file(...), RecallBench::LANGUAGES);
        $results = [];
        foreach (RecallBench::LANGUAGES as $i => $language) {
            $results[$language] = RecallBench::measure(Recall::HRR_WEIGHT, $files[$i]);
        }
        $results['mixed'] = RecallBench::measure(Recall::HRR_WEIGHT, $files[0], $files);

        foreach (self::FLOORS as $set => [$recall, $extra, $silence]) {
            $r = $results[$set];
            self::assertGreaterThanOrEqual(15, $r['quiet'], $set . ': questions memory must stay silent on');
            self::assertGreaterThanOrEqual($recall, $r['recall'], $set . ': recall@5');
            self::assertLessThanOrEqual($extra, $r['extra'], $set . ': extra facts per question');
            self::assertGreaterThanOrEqual($silence, $r['silence'], $set . ': silence');
        }
    }

    public function testHrrDoesNotMakeTheOrderWorseThanWordsAlone(): void {
        [$words, $wordsRank, $count] = RecallBench::run(0.0);
        [$hrr, $hrrRank] = RecallBench::run(Recall::HRR_WEIGHT);

        self::assertGreaterThanOrEqual(140, $count);
        self::assertGreaterThanOrEqual($words, $hrr);
        self::assertGreaterThanOrEqual($wordsRank, $hrrRank);
    }
}
