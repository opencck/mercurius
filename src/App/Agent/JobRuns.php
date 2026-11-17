<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\Run;

/**
 * Runs of scheduled jobs: they belong to no chat, so the job scheduler, not the run scheduler, goes on with them
 * after the owner's decision or a restart, and delivers the result to the chat of the job.
 *
 * spec: BRIEF.md#планировщик-и-проекты
 */
interface JobRuns {
    public function owns(Run $run): bool;

    /** $decided — after the owner's decision (the run is waiting); otherwise after a restart (the run is running). */
    public function continueRun(Run $run, bool $decided): void;
}
