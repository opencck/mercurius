<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\App\Agent\RunObserver;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;

final class RecordingObserver implements RunObserver {
    /** @var list<string> */
    public array $tools = [];
    public string $streamed = '';
    public ?string $final = null;
    public ?StopReason $reason = null;
    public ?string $waiting = null;

    public function toolStarted(Run $run, string $name, string $argsPreview): void {
        $this->tools[] = $name;
    }

    public function textDelta(Run $run, string $text): void {
        $this->streamed .= $text;
    }

    public function finished(Run $run, string $finalText, ?StopReason $reason): void {
        $this->final = $finalText;
        $this->reason = $reason;
    }

    public function waiting(Run $run, string $text): void {
        $this->waiting = $text;
    }
}
