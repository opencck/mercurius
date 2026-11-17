<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;

/**
 * What the channel shows while a run works: the current step, the streamed answer, the final text.
 */
interface RunObserver {
    public function toolStarted(Run $run, string $name, string $argsPreview): void;

    public function textDelta(Run $run, string $text): void;

    /**
     * Text the model wrote together with tool calls: it is said to the person, so it stays in the chat.
     *
     * ref: references/hermes-agent/gateway/run_turn_runner.py@e96221fa:L368-L398 (interim_assistant_cb) — commentary
     *      between tool calls is delivered as its own message, not overwritten by the next step
     */
    public function commentary(Run $run, string $text): void;

    /** The run paused for the owner's approval. */
    public function waiting(Run $run, string $text): void;

    /** Called once when the run ends (not on shutdown: the run goes on after restart). */
    public function finished(Run $run, string $finalText, ?StopReason $reason): void;
}
