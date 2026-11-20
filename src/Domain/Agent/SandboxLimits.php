<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use InvalidArgumentException;

/**
 * A participant's share of the sandbox.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты — per-participant limits inside one sandbox
 */
final class SandboxLimits {
    /** @var positive-int size of the participant's directory */
    public readonly int $diskBytes;

    /** @var positive-int processes of the participant's user */
    public readonly int $processes;

    /** @var positive-int CPU time per command */
    public readonly int $cpuSeconds;

    /**
     * Address space per command; 0 — no limit (the default: WebAssembly in Node reserves ~10 GB of addresses, and the
     * real memory is held by the sandbox container's limit).
     *
     * @var non-negative-int
     */
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
        if ($memoryBytes < 0) {
            throw new InvalidArgumentException(sprintf('memoryBytes must not be negative, got %d', $memoryBytes));
        }
        $this->memoryBytes = $memoryBytes;
        $this->concurrentCommands = self::positive('concurrentCommands', $concurrentCommands);
    }

    public function min(self $cap): self {
        return new self(
            min($this->diskBytes, $cap->diskBytes),
            min($this->processes, $cap->processes),
            min($this->cpuSeconds, $cap->cpuSeconds),
            // 0 is no limit: a set limit wins over none
            $this->memoryBytes === 0 || $cap->memoryBytes === 0 ? max($this->memoryBytes, $cap->memoryBytes) : min($this->memoryBytes, $cap->memoryBytes),
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
