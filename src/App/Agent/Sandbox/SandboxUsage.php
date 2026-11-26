<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * How much of the participant's disk in the sandbox is used, for the agent to know what is left.
 *
 * spec: brief/agent-loop.md#ресурсы-сервера-и-песочницы
 */
interface SandboxUsage {
    /**
     * @return non-negative-int bytes in the participant's folder
     * @throws SandboxFailure
     */
    public function usage(int $principalId, SandboxLimits $limits, Cancellation $cancellation): int;
}
