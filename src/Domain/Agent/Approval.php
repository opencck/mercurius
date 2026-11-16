<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * A paused tool call waiting for the owner. The id is short and goes into the button data.
 *
 * spec: BRIEF.md#подтверждения-и-запреты
 */
final class Approval {
    public function __construct(
        public readonly int $id,
        public readonly int $runId,
        public readonly string $callId,
        public readonly int $principalId,
        public readonly string $tool,
        public readonly string $summary,
        public readonly ?string $grantKey,
        public readonly ApprovalStatus $status,
        public readonly ?string $reason = null,
        public readonly ?string $cardChatId = null,
        public readonly ?string $cardMessageId = null,
    ) {}
}
