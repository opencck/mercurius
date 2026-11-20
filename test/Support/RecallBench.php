<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;

/**
 * recall@5 of memory search on a set of facts and questions (test/Fixtures/memory-recall-ru.json).
 *
 * spec: brief/memory.md#hrr-с-самого-начала — the share of HRR is measured on Russian questions, not assumed
 */
final class RecallBench {
    /** @return array{float, float, int} recall@5, mean reciprocal rank of the first expected fact, the number of questions */
    public static function run(float $hrrWeight, ?string $file = null): array {
        /** @var array{facts: list<string>, questions: list<array{q: string, expect: list<int>}>} $set */
        $set = json_decode((string) file_get_contents($file ?? dirname(__DIR__) . '/Fixtures/memory-recall-ru.json'), true, 8, JSON_THROW_ON_ERROR);
        $store = new InMemoryMemoryStore();
        $analyzer = new TextAnalyzer();
        $ids = [];
        foreach ($set['facts'] as $i => $text) {
            $ids[$i] = $store->addFact('owner', Fact::KNOWLEDGE, $text, $analyzer->keys($text), 0.8, Fact::OWNER_SAID, null)->id;
        }
        $recall = new Recall($store, $analyzer, null, $hrrWeight);
        $hits = 0;
        $reciprocal = 0.0;
        foreach ($set['questions'] as $question) {
            $ranked = array_map(static fn(Fact $f): int => $f->id, $recall->find($question['q'], ['owner'], null, 100_000));
            $best = null;
            foreach ($question['expect'] as $index) {
                $at = array_search($ids[$index] ?? -1, $ranked, true);
                if ($at !== false && ($best === null || $at < $best)) {
                    $best = $at;
                }
            }
            if ($best !== null) {
                $hits += $best < 5 ? 1 : 0;
                $reciprocal += 1.0 / (float) ($best + 1);
            }
        }
        $count = max(1, count($set['questions']));
        return [(float) $hits / (float) $count, $reciprocal / (float) $count, count($set['questions'])];
    }
}
