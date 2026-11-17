<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Memory\App\Recall;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Memory\Infrastructure\PostgresMemoryStore;
use OpenCCK\Test\Support\TestDatabase;

/**
 * Recall before a run must not hold the answer up: the Russian set against PostgreSQL, words and HRR, p95 of the time.
 *
 * spec: BRIEF.md#фаза-6-по-частям — recall within a budget of time (p95)
 *
 * @group integration
 */
final class RecallLatencyTest extends AsyncTestCase {
    public const P95_MS = 250.0;

    public function testP95OfRecallOnTheRussianSet(): void {
        $db = TestDatabase::connect();
        $store = new PostgresMemoryStore($db);
        $analyzer = new TextAnalyzer();
        /** @var array{facts: list<string>, questions: list<array{q: string, expect: list<int>}>} $set */
        $set = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/memory-recall-ru.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($set['facts'] as $text) {
            $store->addFact('owner', Fact::KNOWLEDGE, $text, $analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
        }
        $recall = new Recall($store, $analyzer, null, 0.3);
        $times = [];
        for ($round = 0; $round < 3; $round++) {
            foreach ($set['questions'] as $question) {
                $started = hrtime(true);
                $recall->find($question['q'], ['owner']);
                $times[] = (float) (hrtime(true) - $started) / 1e6;
            }
        }
        sort($times);
        $p95 = $times[(int) floor(0.95 * (float) (count($times) - 1))];
        fwrite(STDERR, sprintf("\nrecall p95 %.1f ms, median %.1f ms over %d calls\n", $p95, $times[intdiv(count($times), 2)], count($times)));
        $db->close();

        self::assertLessThan(self::P95_MS, $p95);
    }
}
