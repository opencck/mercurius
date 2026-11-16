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

    /** Called once when the run ends (not on shutdown: the run goes on after restart). */
    public function finished(Run $run, string $finalText, ?StopReason $reason): void;
}
