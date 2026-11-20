<?php

declare(strict_types=1);

namespace OpenCCK\App\Improve;

use OpenCCK\Domain\Repository\ImprovementRepository;

use function Mercurius\I18n\t;

/**
 * Notes to the system prompt that the owner accepted from the agent's proposals. A version never changes; the active
 * one is a pointer: a new version, a rollback and "off" only move it.
 *
 * spec: brief/self-improvement.md#самоулучшение
 * ref: docs/notes/claude-cookbooks.md — a prompt version is immutable, a pointer moves; the version is recorded with results
 */
final class PromptNotes {
    public const MAX = 4000;

    public function __construct(private readonly ImprovementRepository $store) {}

    /**
     * The key of a version of the notes by its text, for the scores of the evaluation set: the same text is the same
     * version, whatever its number; 'none' — no notes.
     */
    public static function versionKey(string $text): string {
        $text = trim($text);
        return $text === '' ? 'none' : 'h' . substr(sha1($text), 0, 10);
    }

    /** How a scored version is named: `v<number>` when one of the notes has that text, the key otherwise. */
    public function label(string $key): string {
        if ($key === 'none') {
            return t('improve.scores.no_notes');
        }
        foreach ($this->store->notes(50) as $note) {
            if (self::versionKey($note['text']) === $key) {
                return 'v' . $note['version'];
            }
        }
        return $key;
    }

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
