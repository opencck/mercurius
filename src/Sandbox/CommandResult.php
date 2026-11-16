<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

final class CommandResult {
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut = false,
        public readonly bool $cancelled = false,
        public readonly int $durationMs = 0,
        public readonly bool $overQuota = false,
    ) {}

    /** @return array<string, int|string|bool> */
    public function toArray(): array {
        return [
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'timed_out' => $this->timedOut,
            'cancelled' => $this->cancelled,
            'duration_ms' => $this->durationMs,
            'over_quota' => $this->overQuota,
        ];
    }
}
