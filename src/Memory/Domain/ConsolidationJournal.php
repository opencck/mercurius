<?php

declare(strict_types=1);

namespace OpenCCK\Memory\Domain;

/**
 * What the nightly check of memory remembers between nights: each pass with a snapshot of memory, and what was
 * already shown to the owner.
 *
 * spec: BRIEF.md#консолидация-dreaming
 */
interface ConsolidationJournal {
    public const SKIPPED = 'skipped';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** @return array{at: int, facts: int, chars: int, status: string}|null the last pass that looked at memory */
    public function last(): ?array;

    /** @param list<string> $report */
    public function save(string $status, int $facts, int $chars, int $durationMs, array $report): void;

    /**
     * Whether a finding may be shown now: not shown within the pause. When it may, it counts as shown from now.
     */
    public function notice(string $kind, int $a, int $b, int $pauseDays): bool;
}
