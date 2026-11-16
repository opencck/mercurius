<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

use Closure;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\TextAnalyzer;

/**
 * Facts that answer a message, not merely share a word with it.
 *
 * spec: BRIEF.md#память-слои — coverage of the message's informative keys weighted by IDF within the readable
 *       scopes, times trust and recency; a budget in characters; a fact already given in this conversation is not
 *       repeated within the window
 * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L964-L1060 (relevant) — IDF weights, words no fact
 *      holds count against coverage at the smallest weight, a matched identifier is decisive, one shared word that
 *      other facts hold too does not pick a fact out; without n-grams, name forms and the FTS index: keys are stems
 */
final class Recall {
    public const BUDGET = 1500;
    public const MIN_COVERAGE = 0.34;
    public const WINDOW = 20;
    private const CANDIDATES = 100;
    private const HALF_LIFE_DAYS = 180.0;

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now unix seconds */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): int => time();
    }

    /**
     * @param list<string> $scopes
     * @return list<Fact> best first, within the budget; marked as given when a conversation is named
     */
    public function find(string $message, array $scopes, ?int $conversationId = null, int $budget = self::BUDGET): array {
        $keys = $this->analyzer->keys($message);
        if ($keys === [] || $scopes === []) {
            return [];
        }
        $df = $this->store->frequencies($scopes, $keys);
        $total = (float) max($df[''], 1);
        $weights = [];
        foreach ($keys as $key) {
            if (($df[$key] ?? 0) > 0) {
                $weights[$key] = log(1.0 + $total / (float) $df[$key]);
            }
        }
        if ($weights === []) {
            return [];
        }
        $unknown = count($keys) - count($weights);
        $sum = array_sum($weights) + (float) $unknown * min($weights);
        $given = $conversationId === null ? [] : array_flip($this->store->recalled($conversationId, self::WINDOW));
        $now = ($this->now)();

        // array keys of numeric words come back as ints
        $known = array_map(strval(...), array_keys($weights));
        /** @var list<array{Fact, float}> $scored */
        $scored = [];
        foreach ($this->store->candidates($scopes, $known, self::CANDIDATES) as $fact) {
            if (isset($given[$fact->id])) {
                continue;
            }
            $matched = array_values(array_intersect($known, $fact->keys));
            if ($matched === []) {
                continue;
            }
            $identifier = false;
            foreach ($matched as $key) {
                $identifier = $identifier || TextAnalyzer::isIdentifier($key);
            }
            $coverage = array_sum(array_map(static fn(string $k): float => $weights[$k], $matched)) / $sum;
            // One shared word of a longer message, held by other facts too ("what day is it" and "every day"), is
            // about none of them.
            $lone = count($keys) > 1 && count($matched) === 1 && ($df[$matched[0]] ?? 0) > 1;
            if (!$identifier && ($coverage < self::MIN_COVERAGE || $lone)) {
                continue;
            }
            $age = (float) max(0, $now - $fact->updatedAt) / 86400.0;
            $scored[] = [$fact, ($identifier ? max($coverage, 1.0) : $coverage) * $fact->trust * 0.5 ** ($age / self::HALF_LIFE_DAYS)];
        }
        usort($scored, self::better(...));

        $found = [];
        $left = $budget;
        foreach ($scored as [$fact]) {
            $length = mb_strlen(self::line($fact)) + 1;
            if ($length > $left) {
                continue;
            }
            $left -= $length;
            $found[] = $fact;
        }
        if ($conversationId !== null && $found !== []) {
            $this->store->markRecalled($conversationId, array_map(static fn(Fact $f): int => $f->id, $found));
        }
        return $found;
    }

    /**
     * @param array{Fact, float} $a
     * @param array{Fact, float} $b
     */
    private static function better(array $a, array $b): int {
        return $b[1] <=> $a[1] ?: $b[0]->id <=> $a[0]->id;
    }

    /** @param list<Fact> $facts one line per fact, whole */
    public static function format(array $facts): string {
        return implode("\n", array_map(self::line(...), $facts));
    }

    private static function line(Fact $fact): string {
        return sprintf('[#%d] %s', $fact->id, $fact->text);
    }
}
