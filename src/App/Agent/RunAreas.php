<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\Run;

/**
 * The areas a run works in — a job, a project — and whether it has anybody to ask. A grant may be given to an
 * area as a whole; a run with nobody to ask does not wait for a card: it is refused and the lacking grant joins one
 * list for the owner.
 *
 * spec: brief/agent-loop.md#разрешения-навсегда
 * spec: brief/scheduler.md#разрешения-задания
 */
interface RunAreas {
    /** @return list<string> "job:5", "project:8" */
    public function of(Run $run): array;

    /** The area of a run nobody watches ("job:5"); null — the owner can be asked. */
    public function unattended(Run $run): ?string;
}
