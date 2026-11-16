<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * Runs a shell command as the participant: prlimit sets the per-command limits (processes of the user, CPU seconds,
 * address space), setpriv drops to the participant's uid with no supplementary groups, env -i leaves only a minimal
 * environment, setsid gives the command its own session so a timeout kills everything it started.
 *
 * spec: BRIEF.md#песочница — commands as the participant, limits, clean environment, kill on timeout
 * ref: references/hermes-agent/tools/environments/docker.py@e96221f:L328-L410 (_BASE_SECURITY_ARGS,
 *      _build_security_args) — least privilege; hermes isolates per container, we isolate per Unix user inside one
 */
final class CommandRunner implements Runner {
    public const PATH = '/usr/local/go/bin:/usr/local/bin:/usr/bin:/bin';

    /** @var array<int, int> running commands per participant */
    private array $running = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Users $users,
        private readonly FileStore $files,
        private readonly Proc $proc = new Proc(),
    ) {}

    public function run(
        int $principalId,
        SandboxLimits $limits,
        string $command,
        string $cwd,
        int $timeoutSeconds,
        ?string $stdin,
        Cancellation $cancellation,
    ): CommandResult {
        $this->users->ensure($principalId);
        if (($this->running[$principalId] ?? 0) >= $limits->concurrentCommands) {
            throw new SandboxError(sprintf('Слишком много одновременных команд (не больше %d).', $limits->concurrentCommands), 429);
        }
        $this->running[$principalId] = ($this->running[$principalId] ?? 0) + 1;
        try {
            $result = $this->proc->run($this->argv($principalId, $limits, $command), $cwd, $timeoutSeconds, $stdin, $cancellation);
        } finally {
            $this->running[$principalId]--;
        }
        $over = $this->files->usage($principalId) > $limits->diskBytes;
        return new CommandResult($result->exitCode, $result->stdout, $result->stderr, $result->timedOut, $result->cancelled, $result->durationMs, $over);
    }

    /** @return list<string> */
    public function argv(int $principalId, SandboxLimits $limits, string $command): array {
        $uid = (string) $this->workspace->uid($principalId);
        return [
            'prlimit',
            '--nproc=' . $limits->processes,
            '--cpu=' . $limits->cpuSeconds,
            '--as=' . $limits->memoryBytes,
            '--',
            'setpriv', '--reuid=' . $uid, '--regid=' . $uid, '--clear-groups', '--',
            'env', '-i',
            'HOME=' . $this->workspace->home($principalId),
            'USER=' . $this->workspace->user($principalId),
            'PATH=' . self::PATH,
            'LANG=C.UTF-8',
            'setsid', '--wait',
            'bash', '-c', $command,
        ];
    }
}
