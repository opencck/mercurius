<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;
use SensitiveParameter;

/**
 * Long-lived processes in the sandbox: spawn one, write to its stdin, take its stdout by long polling, close it.
 * stdio MCP servers run on this.
 *
 * spec: brief/agent-loop.md#песочница — the streaming API of the executor
 */
interface SandboxStreams {
    /**
     * @param array<string, string> $env extra environment (a token): into the environment, not the arguments
     * @return string the session
     * @throws SandboxFailure
     */
    public function spawn(int $principalId, SandboxLimits $limits, string $command, #[SensitiveParameter] array $env, Cancellation $cancellation): string;

    /** @throws SandboxFailure */
    public function send(int $principalId, SandboxLimits $limits, string $session, string $data, Cancellation $cancellation): void;

    /**
     * Output since the last call, waiting up to $wait seconds for some.
     *
     * @return array{stdout: string, exited: bool, exitCode: ?int, stderr: string}
     * @throws SandboxFailure
     */
    public function receive(int $principalId, SandboxLimits $limits, string $session, int $wait, Cancellation $cancellation): array;

    public function close(int $principalId, SandboxLimits $limits, string $session): void;

    /** Closes every process of a holder, the ones a restarted application left behind among them. */
    public function closeAll(int $principalId, SandboxLimits $limits): void;
}
