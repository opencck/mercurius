<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

use Closure;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\Hrr;
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
 * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L615-L636 (_rerank) — HRR as a share of the score of
 *      candidates the words already picked, shifted to [0, 1]; it never lets a fact past the gate
 */
final class Recall {
    public const BUDGET = 1500;
    public const MIN_COVERAGE = 0.34;
    public const WINDOW = 20;
    private const CANDIDATES = 100;
    private const HALF_LIFE_DAYS = 180.0;
    /** share of HRR in the score of a fact that passed the gate */
    public const HRR_WEIGHT = 0.3;
    public const RELATED_LIMIT = 10;
    /**
     * A fact under this trust is not recalled: two «not useful» marks sink a fact the agent inferred.
     * ref: references/hermes-plugin-holographic/__init__.py@4a794f8:L761 — min_trust_threshold 0.3
     */
    public const MIN_TRUST = 0.3;

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now unix seconds */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        ?Closure $now = null,
        private readonly float $hrrWeight = self::HRR_WEIGHT,
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
        /** @var list<array{Fact, float, float}> $passed fact, its coverage score, its age factor */
        $passed = [];
        foreach ($this->store->candidates($scopes, $known, self::CANDIDATES) as $fact) {
            if (isset($given[$fact->id]) || $fact->trust < self::MIN_TRUST - 1e-9) {
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
            $passed[] = [$fact, $identifier ? max($coverage, 1.0) : $coverage, $fact->trust * 0.5 ** ($age / self::HALF_LIFE_DAYS)];
        }
        $hrr = [];
        if ($this->hrrWeight > 0.0 && count($passed) > 1) {
            $hrr = $this->store->similarities(array_map(static fn(array $p): int => $p[0]->id, $passed), Hrr::phasor(Hrr::query($keys)));
        }
        /** @var list<array{Fact, float}> $scored */
        $scored = [];
        foreach ($passed as [$fact, $coverage, $factor]) {
            $relevance = isset($hrr[$fact->id]) ? (1.0 - $this->hrrWeight) * $coverage + $this->hrrWeight * ($hrr[$fact->id] + 1.0) / 2.0 : $coverage;
            $scored[] = [$fact, $relevance * $factor];
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
     * Facts tied to a word or a name by HRR: as an entity or as a word of the content.
     *
     * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L819-L839 (related) — the best of the entity and
     *      content probes against the noise threshold; one database query instead of a pass over every vector
     *
     * @param list<string> $scopes
     * @return list<Fact>
     */
    public function related(string $word, array $scopes, int $limit = self::RELATED_LIMIT): array {
        $keys = $this->analyzer->keys($word);
        if ($keys === [] || $scopes === []) {
            return [];
        }
        $probes = [];
        foreach ($keys as $key) {
            foreach (Hrr::probes($key) as $probe) {
                $probes[] = Hrr::phasor($probe);
            }
        }
        return array_values(array_filter(
            $this->store->related($scopes, $probes, Hrr::RELATED_THRESHOLD, $limit),
            static fn(Fact $f): bool => $f->trust >= self::MIN_TRUST - 1e-9,
        ));
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
