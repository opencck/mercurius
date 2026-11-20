<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use OpenCCK\App\Service\SystemPrompt;
use PHPUnit\Framework\TestCase;

/**
 * The system prompt changes only with its version: the version tells open conversations to take the new text (and the
 * prompt cache of the provider to start again). A text changed without it would stay unseen in old conversations.
 *
 * spec: brief/agent-loop.md#запуск-и-цикл — a snapshot of the system prompt
 */
final class SystemPromptSnapshotTest extends TestCase {
    private const SNAPSHOT = __DIR__ . '/../../../Fixtures/prompt/system-prompt.txt';

    public function testTheTextChangesOnlyWithItsVersion(): void {
        $now = SystemPrompt::VERSION . ' ' . hash('sha256', SystemPrompt::text());
        $kept = trim((string) file_get_contents(self::SNAPSHOT));

        self::assertSame($kept, $now, 'the system prompt changed: raise SystemPrompt::VERSION and write "<version> <sha256>" into ' . self::SNAPSHOT);
    }
}
