<?php

declare(strict_types=1);

namespace OpenCCK\App\Improve;

/** What the evaluation set said about a change: whether it may be taken, the report for the owner, the numbers. */
final class GateVerdict {
    /** @param array<string, mixed> $numbers */
    public function __construct(
        public readonly bool $passed,
        public readonly string $report,
        public readonly array $numbers = [],
    ) {}
}
