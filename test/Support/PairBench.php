<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\GrayZone;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;

/**
 * The gray zone of sleep measured on pairs of facts (test/Fixtures/memory-pairs-<lang>.json): each pair in a memory of
 * its own, with the other facts of the set beside it — how many pairs that need the model reach it, how many unrelated
 * ones are sent anyway, and how many pairs the code settles itself as copies.
 *
 * spec: brief/memory.md#серая-зона
 */
final class PairBench {
    public const LANGUAGES = ['ru', 'en', 'zh'];

    public static function file(string $language): string {
        return dirname(__DIR__) . '/Fixtures/memory-pairs-' . $language . '.json';
    }

    /**
     * @return array{related: int, reached: int, unrelated: int, sent: int, copies: int, total: int, labels: array<string, array{int, int}>}
     *         related — pairs that are not «unrelated»; reached — of them, taken to the model or settled as a copy;
     *         sent — unrelated pairs taken to the model; total — all pairs taken from the whole set (noise included)
     */
    public static function measure(string $file): array {
        /** @var array{pairs: list<array{a: string, b: string, label: string}>} $set */
        $set = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        $analyzer = new TextAnalyzer();
        $store = new InMemoryMemoryStore();
        $facts = [];
        $pairs = [];
        foreach ($set['pairs'] as $pair) {
            $ids = [];
            foreach ([$pair['a'], $pair['b']] as $text) {
                $fact = $facts[$text] ?? $store->addFact('owner', Fact::KNOWLEDGE, $text, $analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
                $facts[$text] = $fact;
                $ids[] = $fact->id;
            }
            sort($ids);
            $pairs[] = [$ids, $pair['label'], $facts[$pair['a']], $facts[$pair['b']]];
        }
        $taken = [];
        foreach ((new GrayZone())->pairs(array_values($facts), 100_000) as [$a, $b]) {
            $key = [$a->id, $b->id];
            sort($key);
            $taken[implode(':', $key)] = true;
        }
        $result = ['related' => 0, 'reached' => 0, 'unrelated' => 0, 'sent' => 0, 'copies' => 0, 'total' => count($taken), 'labels' => []];
        foreach ($pairs as [$ids, $label, $a, $b]) {
            $copy = Consolidation::duplicates($a, $b);
            $hit = $copy || isset($taken[implode(':', $ids)]);
            $result['labels'][$label] ??= [0, 0];
            $result['labels'][$label][1]++;
            $result['labels'][$label][0] += $hit ? 1 : 0;
            $result['copies'] += $copy ? 1 : 0;
            if ($label === 'unrelated') {
                $result['unrelated']++;
                $result['sent'] += $hit ? 1 : 0;
            } else {
                $result['related']++;
                $result['reached'] += $hit ? 1 : 0;
            }
        }
        ksort($result['labels']);
        return $result;
    }
}
