<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * Runs a shell command as the participant's user, inside their limits.
 */
interface Runner {
    /**
     * @param string $cwd an absolute path already resolved inside the participant's home
     * @param positive-int $timeoutSeconds
     * @throws SandboxError
     */
    public function run(
        int $principalId,
        SandboxLimits $limits,
        string $command,
        string $cwd,
        int $timeoutSeconds,
        ?string $stdin,
        Cancellation $cancellation,
    ): CommandResult;
}
