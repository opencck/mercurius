<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\StopReason;

/**
 * The verdict of one phase of a turn: the run goes on with the next turn, or stops with a reason (null — it waits for
 * the owner).
 *
 * spec: brief/agent-loop.md#подходы
 */
final class Step {
    private function __construct(
        public readonly bool $goesOn,
        public readonly ?StopReason $reason,
    ) {}

    public static function next(): self {
        return new self(true, null);
    }

    public static function stop(?StopReason $reason): self {
        return new self(false, $reason);
    }
}
