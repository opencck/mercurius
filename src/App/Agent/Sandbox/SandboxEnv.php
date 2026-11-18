<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;
use SensitiveParameter;

/**
 * A command with an environment of its own (a token for git): the values reach the process, not its arguments.
 */
interface SandboxEnv {
    /**
     * @param positive-int $timeoutSeconds
     * @param array<string, string> $env
     * @throws SandboxFailure
     */
    public function execEnv(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, #[SensitiveParameter] array $env, Cancellation $cancellation): CommandOutcome;
}
