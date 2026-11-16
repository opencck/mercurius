<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

final class CommandOutcome {
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly bool $overQuota,
        public readonly int $durationMs,
    ) {}
}
