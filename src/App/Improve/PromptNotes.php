<?php

declare(strict_types=1);

namespace OpenCCK\App\Improve;

use OpenCCK\Domain\Repository\ImprovementRepository;

use function Mercurius\I18n\t;

/**
 * Notes to the system prompt that the owner accepted from the agent's proposals. A version never changes; the active
 * one is a pointer: a new version, a rollback and "off" only move it.
 *
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18
 * ref: notes/claude-cookbooks.md — a prompt version is immutable, a pointer moves; the version is recorded with results
 */
final class PromptNotes {
    public const MAX = 4000;

    public function __construct(private readonly ImprovementRepository $store) {}

    /** The text of the active version; '' when none is active. */
    public function active(): string {
        return $this->store->activeNote()['text'] ?? '';
    }

    /** `n<version>` of the active version for the records of conversations; '' when none. */
    public function tag(): string {
        $note = $this->store->activeNote();
        return $note === null ? '' : 'n' . $note['version'];
    }

    public function add(string $text, string $reason): int {
        return $this->store->addNote(mb_substr(trim($text), 0, self::MAX), mb_substr(trim($reason), 0, 500));
    }

    public function activate(?int $version): bool {
        return $this->store->activate($version);
    }

    public function text(): string {
        $notes = $this->store->notes(15);
        if ($notes === []) {
            return t('improve.notes.none');
        }
        $lines = [];
        foreach ($notes as $note) {
            $lines[] = sprintf('%s v%d · %s · %s', $note['active'] ? '▶' : '·', $note['version'], gmdate('Y-m-d', $note['created']), mb_substr(str_replace("\n", ' ', $note['text']), 0, 200));
        }
        return t('improve.notes.list') . "\n" . implode("\n", $lines);
    }
}
