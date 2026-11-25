<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Entity\Member;

/**
 * Asks the owner about an irreversible call: records the pending approval and shows the card. The loop pauses
 * the run afterwards; the owner's decision resumes it.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты — a paused tool call, the owner answers through the channel
 */
interface ApprovalGate {
    /** @param ?Member $decider the participant who decides instead of the owner: the card goes to their private chat */
    public function request(Run $run, ValidatedCall $call, Member $principal, ?Member $decider = null): void;
}
