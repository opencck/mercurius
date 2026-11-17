<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

use Closure;
use InvalidArgumentException;
use OpenCCK\Memory\Domain\ConsolidationJournal;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;

/**
 * The nightly check of memory, by code alone: duplicates become a proposal to forget one of them, facts that disagree
 * in numbers and facts nobody used are listed for the owner, a sharp drop of memory raises an alert. Nothing is
 * changed without the owner; no model is called; a night with no changes in memory does nothing.
 *
 * spec: BRIEF.md#консолидация-dreaming
 * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@21878d3:L3035-L3068 (find_conflicts) — shared words
 *      and differing numbers make a conflict, not a duplicate; L4614-L4737 (check_memory_loss) — more than 25% of
 *      entries or characters gone, counted from 4 entries or 400 characters; L5184-L5190 (_decay_candidates) — unused,
 *      old, low trust, a question and not a deletion; one duplicate pair a night, paused 180 days (scoring.md:L554-L570)
 */
final class Consolidation {
    public const FACTS = 2000;
    public const PAIR_BUDGET = 200_000;
    public const DUPLICATE_JACCARD = 0.8;
    public const CONFLICT_SHARED = 3;
    public const CONFLICT_OVERLAP = 0.45;
    public const LOSS_SHARE = 0.25;
    public const LOSS_MIN_FACTS = 4;
    public const LOSS_MIN_CHARS = 400;
    public const UNUSED_DAYS = 28;
    public const UNUSED_TRUST = 0.5;
    public const PAUSE_DUPLICATE = 180;
    public const PAUSE_NOTICE = 14;
    public const LIST_CAP = 5;
    private const LINE = 160;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param Closure(Proposal): bool $show sends a proposal's card to the owner
     * @param Closure(string): void $tell writes to the owner
     * @param (Closure(): int)|null $now unix seconds
     */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly Proposals $proposals,
        private readonly ConsolidationJournal $journal,
        private readonly Closure $show,
        private readonly Closure $tell,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): int => time();
    }

    /**
     * @param bool $force check even when memory has not changed since the last pass
     * @return string what was done, for the log or the owner's command
     */
    public function run(bool $force = false): string {
        $started = hrtime(true);
        $facts = $this->store->active(self::FACTS);
        $count = count($facts);
        $chars = array_sum(array_map(static fn(Fact $f): int => mb_strlen($f->text), $facts));
        $last = $this->journal->last();
        $changed = $last === null || $last['facts'] !== $count || $last['chars'] !== $chars
            || array_filter($facts, static fn(Fact $f): bool => $f->updatedAt >= $last['at']) !== [];
        if (!$force && !$changed) {
            $this->journal->save(ConsolidationJournal::SKIPPED, $count, $chars, self::ms($started), []);
            return 'Память не менялась — проверять нечего.';
        }

        $report = [];
        $loss = $last === null ? null : self::loss($last['facts'], $last['chars'], $count, $chars);
        if ($loss !== null) {
            $report[] = $loss;
        }
        $duplicate = $this->duplicate($facts);
        if ($duplicate !== null) {
            $report[] = $duplicate;
        }
        array_push($report, ...$this->conflicts($facts), ...$this->unused($facts));

        $this->journal->save(ConsolidationJournal::DONE, $count, $chars, self::ms($started), $report);
        if ($report === []) {
            return sprintf('Проверено фактов: %d. Замечаний нет.', $count);
        }
        $text = "Память: ночная проверка.\n\n" . implode("\n\n", $report);
        ($this->tell)($text);
        return $text;
    }

    private static function loss(int $facts, int $chars, int $nowFacts, int $nowChars): ?string {
        $byFacts = $facts >= self::LOSS_MIN_FACTS && (float) $nowFacts < (float) $facts * (1.0 - self::LOSS_SHARE);
        $byChars = $chars >= self::LOSS_MIN_CHARS && (float) $nowChars < (float) $chars * (1.0 - self::LOSS_SHARE);
        if (!$byFacts && !$byChars) {
            return null;
        }
        return sprintf(
            '⚠ Памяти стало заметно меньше: фактов было %d, стало %d; символов было %d, стало %d. Если это не вы забывали факты — проверьте /memory.',
            $facts,
            $nowFacts,
            $chars,
            $nowChars,
        );
    }

    /**
     * One pair of near-copies a night: the weaker one is proposed to be forgotten.
     *
     * @param list<Fact> $facts
     */
    private function duplicate(array $facts): ?string {
        $pending = [];
        foreach ($this->store->pending() as $proposal) {
            if ($proposal->factId !== null) {
                $pending[$proposal->factId] = true;
            }
        }
        $checked = 0;
        foreach ($facts as $i => $a) {
            foreach (array_slice($facts, $i + 1) as $b) {
                if (++$checked > self::PAIR_BUDGET) {
                    return null;
                }
                if ($a->scope !== $b->scope || isset($pending[$a->id]) || isset($pending[$b->id]) || !self::duplicates($a, $b)) {
                    continue;
                }
                [$keep, $drop] = self::keep($a, $b);
                if (!$this->journal->notice('duplicate', min($a->id, $b->id), max($a->id, $b->id), self::PAUSE_DUPLICATE)) {
                    continue;
                }
                try {
                    $proposal = $this->proposals->propose(
                        Proposal::FORGET,
                        $drop->id,
                        '',
                        sprintf('Ночная проверка: повторяет факт #%d «%s».', $keep->id, self::cut($keep->text)),
                        [$drop->scope],
                        null,
                        null,
                    );
                } catch (InvalidArgumentException) {
                    continue;
                }
                ($this->show)($proposal);
                return sprintf('Факт #%d повторяет #%d — предложил забыть #%d (карточка №%d).', $drop->id, $keep->id, $drop->id, $proposal->id);
            }
        }
        return null;
    }

    /**
     * Facts about the same thing with different numbers: which is right is the owner's call.
     *
     * @param list<Fact> $facts
     * @return list<string>
     */
    private function conflicts(array $facts): array {
        $found = [];
        $checked = 0;
        foreach ($facts as $i => $a) {
            foreach (array_slice($facts, $i + 1) as $b) {
                if (++$checked > self::PAIR_BUDGET || count($found) >= self::LIST_CAP) {
                    break 2;
                }
                if ($a->scope !== $b->scope || !self::conflict($a, $b) || !$this->journal->notice('conflict', $a->id, $b->id, self::PAUSE_NOTICE)) {
                    continue;
                }
                $found[] = sprintf(
                    "Возможно, противоречат друг другу (разные числа):\n#%d %s\n#%d %s\nВерное оставьте, неверное — /forget <номер> или попросите меня изменить.",
                    $a->id,
                    self::cut($a->text),
                    $b->id,
                    self::cut($b->text),
                );
            }
        }
        return $found;
    }

    /**
     * Old facts of low trust that no run was ever given: a question, never a deletion.
     *
     * @param list<Fact> $facts
     * @return list<string>
     */
    private function unused(array $facts): array {
        $old = array_values(array_filter(
            $facts,
            fn(Fact $f): bool => $f->trust <= self::UNUSED_TRUST && $f->updatedAt < ($this->now)() - self::UNUSED_DAYS * 86400,
        ));
        if ($old === []) {
            return [];
        }
        $used = array_flip($this->store->recalledAny(array_map(static fn(Fact $f): int => $f->id, $old)));
        $lines = [];
        foreach ($old as $fact) {
            if (count($lines) >= self::LIST_CAP) {
                break;
            }
            if (!isset($used[$fact->id]) && $this->journal->notice('unused', $fact->id, 0, self::PAUSE_NOTICE)) {
                $lines[] = sprintf('#%d %s', $fact->id, self::cut($fact->text));
            }
        }
        return $lines === [] ? [] : ["Давно не пригождались — нужны ли ещё (если нет — /forget <номер>):\n" . implode("\n", $lines)];
    }

    /** Near-copies: the same numbers, and nearly the same words. */
    public static function duplicates(Fact $a, Fact $b): bool {
        if (self::numbers($a) !== self::numbers($b)) {
            return false;
        }
        $shared = count(array_intersect($a->keys, $b->keys));
        $all = count(array_unique([...$a->keys, ...$b->keys]));
        return $all > 0 && min(count($a->keys), count($b->keys)) >= 2 && (float) $shared / (float) $all >= self::DUPLICATE_JACCARD;
    }

    /** About the same thing, with numbers that differ. */
    public static function conflict(Fact $a, Fact $b): bool {
        $numbersA = self::numbers($a);
        $numbersB = self::numbers($b);
        if ($numbersA === [] || $numbersB === [] || $numbersA === $numbersB) {
            return false;
        }
        $wordsA = array_diff($a->keys, $numbersA);
        $wordsB = array_diff($b->keys, $numbersB);
        $shared = count(array_intersect($wordsA, $wordsB));
        $smaller = min(count($wordsA), count($wordsB));
        return $shared >= self::CONFLICT_SHARED && $smaller > 0 && (float) $shared / (float) $smaller >= self::CONFLICT_OVERLAP;
    }

    /**
     * Which of two copies stays: more keys, then the owner's word over the agent's, then more trust, then the older.
     *
     * @return array{Fact, Fact} keep, drop
     */
    private static function keep(Fact $a, Fact $b): array {
        $rank = static fn(Fact $f): array => [count($f->keys), $f->provenance === Fact::AGENT ? 0 : 1, $f->trust, -$f->id];
        return $rank($a) >= $rank($b) ? [$a, $b] : [$b, $a];
    }

    /** @return list<string> the keys with digits, sorted */
    private static function numbers(Fact $fact): array {
        $numbers = array_values(array_filter($fact->keys, static fn(string $k): bool => preg_match('~\d~', $k) === 1));
        sort($numbers);
        return $numbers;
    }

    private static function cut(string $text): string {
        $text = str_replace("\n", ' ', $text);
        return mb_strlen($text) > self::LINE ? mb_substr($text, 0, self::LINE) . '…' : $text;
    }

    private static function ms(int $started): int {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}
