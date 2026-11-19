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
    /** an irreversible call is waiting for the owner: the loop stops without finishing the run */
    public bool $paused = false;
    /** the provider said the context is too long once in this iteration; the next time the run fails */
    public bool $overflowRetried = false;
    /** Text of an answer cut off by the output limit, continued by the next call. */
    public string $partial = '';
    public int $continuations = 0;
    public int $emptyRetries = 0;
    /** @var list<string|null> per empty answer of the streak: "model|finish" when usage proves no output at all, else null */
    public array $emptyStreak = [];
    /** the last empty answer went to reasoning: the retry asks for less of it */
    public bool $lessReasoning = false;
    /** an answer that reported an action in words without calling a tool, shown back once with a reminder */
    public ?string $textAction = null;
    public bool $textActionRetried = false;
    /** A call that broke mid-stream was repeated once. */
    public bool $streamRetried = false;
    /** The last call delivered at least one stream event before it failed. */
    public bool $streamBegan = false;
    /** The answer as delivered, when it was stitched from parts. */
    public ?string $finalText = null;
    /** The model after a switch on failure; null — the run's own. */
    public ?string $model = null;
    public bool $switched = false;
    /** @var array<string, string> the hash of a long result => the call that gave it first in this run */
    public array $seenResults = [];
    /** the last model call failed with this kind */
    public ?\Mercurius\Llm\ErrorKind $lastError = null;
    /** clock value up to which active time is saved */
    public float $flushedAt;

    /**
     * @param list<Message> $history earlier runs after the summary
     */
    public function __construct(
        public Run $run,
        /** a tool the owner grants during the run joins it (request_tool) */
        public ParticipantPolicy $policy,
        public readonly Principal $principal,
        public readonly RunObserver $observer,
        public readonly Cancellation $cancellation,
        public readonly string $systemPrompt,
        public array $history,
        float $now,
        /** summary of the compacted early part, sent right after the system prompt */
        public ?string $summary = null,
        /** id of the last compacted message: only later ones go to the model */
        public int $uptoId = 0,
    ) {
        $this->guard = new LoopGuard($run->loopHistory);
        $this->flushedAt = $now;
    }
}
