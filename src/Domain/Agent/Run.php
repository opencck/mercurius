<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * One execution of the agent over a task: its model, limits, spend and status.
 *
 * spec: BRIEF.md#запуск-и-цикл
 */
final class Run {
    public function __construct(
        public readonly int $id,
        public readonly int $conversationId,
        public readonly int $principalId,
        public readonly ?int $parentRunId,
        public readonly string $model,
        public readonly RunStatus $status,
        public readonly ?StopReason $stopReason,
        public readonly RunLimits $limits,
        public readonly int $turns = 0,
        public readonly int $tokensUsed = 0,
        public readonly int $tokensReserved = 0,
        public readonly int $activeMs = 0,
        public readonly int $promptAnchor = 0,
        public readonly bool $warned = false,
    ) {}
}
