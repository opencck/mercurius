<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\App\Improve\PromptNotes;

use function Mercurius\I18n\t;

/**
 * The owner's `/prompt`: the versions of the notes to the system prompt, `rollback <version>` and `off`. A version
 * never changes: a rollback moves the pointer, the next conversation gets that version.
 *
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18
 */
final class PromptCommands {
    public function __construct(
        private readonly PromptNotes $notes,
        /** the scores of the evaluation set by version; null — not kept */
        private readonly ?\OpenCCK\Domain\Repository\EvalScoreRepository $scores = null,
    ) {}

    public function handle(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        return match ($parts[0] ?? '') {
            '' => $this->notes->text(),
            'rollback' => $this->notes->activate((int) ltrim($parts[1] ?? '0', 'v')) ? sprintf(t('improve.notes.rolled_back'), ltrim($parts[1] ?? '', 'v')) : t('improve.notes.no_version'),
            'off' => $this->notes->activate(null) ? t('improve.notes.off') : t('improve.notes.no_version'),
            'scores' => $this->scores(),
            default => t('improve.notes.usage'),
        };
    }

    /**
     * The scores of the last two versions the evaluation set ran, category by category.
     *
     * spec: BRIEF.md#самоулучшение-решено-2026-11-18 — a report comparing versions; no pairwise judging (decision В7)
     * ref: notes/claude-cookbooks.md — a score per category, so a regression shows where it happened
     */
    private function scores(): string {
        $versions = $this->scores?->latest(2) ?? [];
        if (count($versions) < 2) {
            return t('improve.scores.none');
        }
        [$new, $old] = $versions;
        $lines = [sprintf(t('improve.scores.head'), $this->notes->label($old['version']), $this->notes->label($new['version']), $new['model'])];
        $categories = array_unique([...array_keys($old['categories']), ...array_keys($new['categories'])]);
        sort($categories);
        foreach ($categories as $category) {
            $a = $old['categories'][$category] ?? null;
            $b = $new['categories'][$category] ?? null;
            $lines[] = sprintf('%s: %s → %s', $category, $a === null ? '—' : sprintf('%.2f', $a['mean']), $b === null ? '—' : sprintf('%.2f', $b['mean']));
        }
        return implode("\n", $lines);
    }
}
