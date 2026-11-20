<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use InvalidArgumentException;

/**
 * spec: brief/agent-loop.md#лимиты
 */
final class RunLimits {
    /** @var positive-int model calls per run */
    public readonly int $maxTurns;

    /** @var positive-int input + output tokens per run */
    public readonly int $tokenBudget;

    /** @var positive-int seconds, approval waits excluded */
    public readonly int $runTimeout;

    /** @var positive-int seconds per tool call */
    public readonly int $toolTimeout;

    public function __construct(
        int $maxTurns,
        int $tokenBudget,
        int $runTimeout,
        int $toolTimeout,
    ) {
        $this->maxTurns = self::positive('maxTurns', $maxTurns);
        $this->tokenBudget = self::positive('tokenBudget', $tokenBudget);
        $this->runTimeout = self::positive('runTimeout', $runTimeout);
        $this->toolTimeout = self::positive('toolTimeout', $toolTimeout);
    }

    public function min(self $cap): self {
        return new self(
            min($this->maxTurns, $cap->maxTurns),
            min($this->tokenBudget, $cap->tokenBudget),
            min($this->runTimeout, $cap->runTimeout),
            min($this->toolTimeout, $cap->toolTimeout),
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
