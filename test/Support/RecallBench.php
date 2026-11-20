<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;

/**
 * Memory search measured on a set of facts and questions (test/Fixtures/memory-recall-<lang>.json): recall@5 and the
 * rank of the first expected fact for questions memory answers, the facts given beside the expected ones, and silence
 * for questions memory must not answer.
 *
 * spec: brief/memory.md#hrr-с-самого-начала — the share of HRR is measured, not assumed
 * spec: brief/memory.md#анализ-текста — precision is measured beside recall: extra facts per question and silence
 */
final class RecallBench {
    public const LANGUAGES = ['ru', 'en', 'zh'];

    /** @return array{float, float, int} recall@5, mean reciprocal rank of the first expected fact, the number of questions */
    public static function run(float $hrrWeight, ?string $file = null): array {
        $result = self::measure($hrrWeight, $file ?? self::file('ru'));
        return [$result['recall'], $result['mrr'], $result['answered']];
    }

    public static function file(string $language): string {
        return dirname(__DIR__) . '/Fixtures/memory-recall-' . $language . '.json';
    }

    /**
     * @param list<string>|null $files several sets in one memory: the mixed-language measure
     * @return array{recall: float, mrr: float, answered: int, extra: float, silence: float, quiet: int, kinds: array<string, array{float, int}>}
     *         recall@5 and MRR over questions with an expected fact; extra — facts given beside the expected ones per such
     *         question; silence — the share of questions with nothing expected that got nothing; kinds — recall@5 and the
     *         count by the kind of question (silence counted as its share)
     */
    public static function measure(float $hrrWeight, string $file, ?array $files = null): array {
        $store = new InMemoryMemoryStore();
        $analyzer = new TextAnalyzer();
        $questions = [];
        foreach ($files ?? [$file] as $path) {
            /** @var array{facts: list<string>, questions: list<array{q: string, expect: list<int>, kind?: string}>} $set */
            $set = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $ids = [];
            foreach ($set['facts'] as $i => $text) {
                $ids[$i] = $store->addFact('owner', Fact::KNOWLEDGE, $text, $analyzer->keys($text), 0.8, Fact::OWNER_SAID, null)->id;
            }
            foreach ($set['questions'] as $question) {
                $questions[] = [
                    'q' => $question['q'],
                    'expect' => array_map(static fn(int $i): int => $ids[$i] ?? -1, $question['expect']),
                    'kind' => $question['kind'] ?? ($question['expect'] === [] ? 'silence' : 'direct'),
                ];
            }
        }
        $recall = new Recall($store, $analyzer, null, $hrrWeight);
        $hits = 0;
        $reciprocal = 0.0;
        $extra = 0;
        $answered = 0;
        $quiet = 0;
        $silent = 0;
        /** @var array<string, array{int, int}> $kinds hits, count */
        $kinds = [];
        foreach ($questions as $question) {
            $ranked = array_map(static fn(Fact $f): int => $f->id, $recall->find($question['q'], ['owner'], null, 100_000));
            $kind = $question['kind'];
            $kinds[$kind] ??= [0, 0];
            $kinds[$kind][1]++;
            if ($question['expect'] === []) {
                $quiet++;
                if ($ranked === []) {
                    $silent++;
                    $kinds[$kind][0]++;
                }
                continue;
            }
            $answered++;
            $best = null;
            foreach ($question['expect'] as $id) {
                $at = array_search($id, $ranked, true);
                if ($at !== false && ($best === null || $at < $best)) {
                    $best = $at;
                }
            }
            $extra += count(array_diff($ranked, $question['expect']));
            if ($best !== null) {
                $hit = $best < 5 ? 1 : 0;
                $hits += $hit;
                $kinds[$kind][0] += $hit;
                $reciprocal += 1.0 / (float) ($best + 1);
            }
        }
        ksort($kinds);
        return [
            'recall' => (float) $hits / (float) max(1, $answered),
            'mrr' => $reciprocal / (float) max(1, $answered),
            'answered' => $answered,
            'extra' => (float) $extra / (float) max(1, $answered),
            'silence' => $quiet === 0 ? 1.0 : (float) $silent / (float) $quiet,
            'quiet' => $quiet,
            'kinds' => array_map(static fn(array $k): array => [(float) $k[0] / (float) max(1, $k[1]), $k[1]], $kinds),
        ];
    }
}
