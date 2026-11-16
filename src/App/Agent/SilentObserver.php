<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;

/** A run nobody watches in the chat: a worker reports to its orchestrator, not to the owner. */
final class SilentObserver implements RunObserver {
    public function toolStarted(Run $run, string $name, string $argsPreview): void {}

    public function textDelta(Run $run, string $text): void {}

    public function waiting(Run $run, string $text): void {}

    public function finished(Run $run, string $finalText, ?StopReason $reason): void {}
}
