<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;
use OpenCCK\Test\Support\RecallBench;
use OpenCCK\Test\Support\TestDatabase;

/**
 * Recall before a run must not hold the answer up: the sets of all three languages in one memory against PostgreSQL,
 * words and HRR, p95 of the time.
 *
 * spec: brief/memory.md#как-сделано-области-и-факты — recall within a budget of time (p95)
 *
 * @group integration
 */
final class RecallLatencyTest extends AsyncTestCase {
    public const P95_MS = 250.0;

    public function testP95OfRecallOnAllTheSets(): void {
        $db = TestDatabase::connect();
        $store = new PostgresMemoryStore($db);
        $analyzer = new TextAnalyzer();
        $questions = [];
        foreach (RecallBench::LANGUAGES as $language) {
            /** @var array{facts: list<string>, questions: list<array{q: string, expect: list<int>}>} $set */
            $set = json_decode((string) file_get_contents(RecallBench::file($language)), true, 8, JSON_THROW_ON_ERROR);
            foreach ($set['facts'] as $text) {
                $store->addFact('owner', Fact::KNOWLEDGE, $text, $analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
            }
            $questions = [...$questions, ...$set['questions']];
        }
        $recall = new Recall($store, $analyzer, null, 0.3);
        $times = [];
        for ($round = 0; $round < 3; $round++) {
            foreach ($questions as $question) {
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
