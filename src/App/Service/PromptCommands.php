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
    public function __construct(private readonly PromptNotes $notes) {}

    public function handle(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        return match ($parts[0] ?? '') {
            '' => $this->notes->text(),
            'rollback' => $this->notes->activate((int) ltrim($parts[1] ?? '0', 'v')) ? sprintf(t('improve.notes.rolled_back'), ltrim($parts[1] ?? '', 'v')) : t('improve.notes.no_version'),
            'off' => $this->notes->activate(null) ? t('improve.notes.off') : t('improve.notes.no_version'),
            default => t('improve.notes.usage'),
        };
    }
}
