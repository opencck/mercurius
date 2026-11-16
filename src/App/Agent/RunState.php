<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\Cancellation;
use Mercurius\Llm\Message;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Entity\Principal;

/**
 * Everything one run's iterations share. Phases read and update it instead of passing locals around.
 *
 * ref: references/hermes-agent/agent/conversation_loop.py@e96221f:L1463-L1543 (_LoopState) — one state object for all phases
 */
final class RunState {
    public readonly LoopGuard $guard;
    /** turns in a row whose calls were all invalid */
    public int $invalidTurns = 0;
    /** the last answer was cut off and the request was already repeated once */
    public bool $truncatedRetried = false;
    /** run messages already sent with the last model call */
    public int $messagesAtCall = 0;
    /** clock value up to which active time is saved */
    public float $flushedAt;

    /** @param list<Message> $history */
    public function __construct(
        public Run $run,
        public readonly ParticipantPolicy $policy,
        public readonly Principal $principal,
        public readonly RunObserver $observer,
        public readonly Cancellation $cancellation,
        public readonly string $systemPrompt,
        public readonly array $history,
        float $now,
    ) {
        $this->guard = new LoopGuard();
        $this->flushedAt = $now;
    }
}
