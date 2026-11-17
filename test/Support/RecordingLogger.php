<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** Keeps every record, for tests that check what was logged. */
final class RecordingLogger extends AbstractLogger {
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
