<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * The sandbox as the core sees it: every call names the participant and their limits. Paths are relative to the
 * participant's home or absolute inside it.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты
 */
interface Sandbox {
    /**
     * @param positive-int $timeoutSeconds
     * @throws SandboxFailure
     */
    public function exec(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, Cancellation $cancellation): CommandOutcome;

    /**
     * @return array{text: string, totalLines: int}
     * @throws SandboxFailure
     */
    public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines, Cancellation $cancellation): array;

    /** @throws SandboxFailure */
    public function write(int $principalId, SandboxLimits $limits, string $path, string $content, Cancellation $cancellation): void;

    /**
     * Writes bytes as they are (a binary file).
     *
     * @throws SandboxFailure
     */
    public function put(int $principalId, SandboxLimits $limits, string $path, string $bytes, Cancellation $cancellation): void;

    /** @throws SandboxFailure */
    public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new, Cancellation $cancellation): void;

    /**
     * @return list<array{path: string, type: string, size: int}>
     * @throws SandboxFailure
     */
    public function list(int $principalId, SandboxLimits $limits, string $path, int $depth, Cancellation $cancellation): array;

    /**
     * The whole file as bytes, up to $maxBytes.
     *
     * @throws SandboxFailure
     */
    public function fetch(int $principalId, SandboxLimits $limits, string $path, int $maxBytes, Cancellation $cancellation): string;
}
