<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use InvalidArgumentException;

/**
 * A participant's share of the sandbox.
 *
 * spec: BRIEF.md#песочница — per-participant limits inside one sandbox
 */
final class SandboxLimits {
    /** @var positive-int size of the participant's directory */
    public readonly int $diskBytes;

    /** @var positive-int processes of the participant's user */
    public readonly int $processes;

    /** @var positive-int CPU time per command */
    public readonly int $cpuSeconds;

    /** @var positive-int address space per command */
    public readonly int $memoryBytes;

    /** @var positive-int commands at the same time */
    public readonly int $concurrentCommands;

    public function __construct(
        int $diskBytes,
        int $processes,
        int $cpuSeconds,
        int $memoryBytes,
        int $concurrentCommands,
    ) {
        $this->diskBytes = self::positive('diskBytes', $diskBytes);
        $this->processes = self::positive('processes', $processes);
        $this->cpuSeconds = self::positive('cpuSeconds', $cpuSeconds);
        $this->memoryBytes = self::positive('memoryBytes', $memoryBytes);
        $this->concurrentCommands = self::positive('concurrentCommands', $concurrentCommands);
    }

    public function min(self $cap): self {
        return new self(
            min($this->diskBytes, $cap->diskBytes),
            min($this->processes, $cap->processes),
            min($this->cpuSeconds, $cap->cpuSeconds),
            min($this->memoryBytes, $cap->memoryBytes),
            min($this->concurrentCommands, $cap->concurrentCommands),
        );
    }

    /** @return positive-int */
    private static function positive(string $name, int $value): int {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be positive, got %d', $name, $value));
        }
        return $value;
    }
}
