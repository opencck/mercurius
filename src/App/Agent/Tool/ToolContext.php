<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Entity\Principal;

/**
 * Who calls the tool, in which run and call, with which policy. The cancellation fires on /stop, shutdown or timeout.
 */
final class ToolContext {
    public function __construct(
        public readonly int $runId,
        public readonly int $conversationId,
        public readonly Principal $principal,
        public readonly Cancellation $cancellation,
        public readonly ?ParticipantPolicy $policy = null,
        public readonly string $callId = '',
    ) {}

    public function withCancellation(Cancellation $cancellation): self {
        return new self($this->runId, $this->conversationId, $this->principal, $cancellation, $this->policy, $this->callId);
    }
}
