<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

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
        array $env = [],
    ): CommandResult {
        self::checkEnv($env);
        $this->users->ensure($principalId);
        if (($this->running[$principalId] ?? 0) >= $limits->concurrentCommands) {
            throw new SandboxError(sprintf(t('sandbox.command_runner.slishkom_mnogo_odnovremennyh_komand_ne'), $limits->concurrentCommands), 429);
        }
        $this->running[$principalId] = ($this->running[$principalId] ?? 0) + 1;
        try {
            // The process starts in the home (as root, before the privilege drop); the requested directory is entered
            // by the participant's shell, so a symlink into another home is refused by the kernel.
            // With an environment of its own the command gets it from the process, not from `env -i` arguments, which
            // other users could read in /proc.
            $result = $env === []
                ? $this->proc->run($this->argv($principalId, $limits, $cwd), $this->workspace->home($principalId), $timeoutSeconds, self::input($command, $stdin), $cancellation)
                : $this->proc->run($this->streamArgv($principalId, $limits, $cwd), $this->workspace->home($principalId), $timeoutSeconds, self::input($command, $stdin), $cancellation, $this->baseEnv($principalId) + $env);
        } finally {
            $this->running[$principalId]--;
        }
        $over = $this->files->usage($principalId) > $limits->diskBytes;
        return new CommandResult($result->exitCode, $result->stdout, $result->stderr, $result->timedOut, $result->cancelled, $result->durationMs, $over);
    }

    /** Names and values the executor accepts: no loader or shell variables, short values, at most a few. */
    public const ENV_MAX = 8;

    /**
     * @param array<string, string> $env
     * @throws SandboxError
     */
    public static function checkEnv(array $env): void {
        if (count($env) > self::ENV_MAX) {
            throw new SandboxError(t('sandbox.streams.bad_env'));
        }
        foreach ($env as $name => $value) {
            if (preg_match('~^[A-Z_][A-Z0-9_]{0,63}$~', $name) !== 1 || in_array($name, ['PATH', 'HOME', 'USER', 'SHELL', 'BASH_ENV', 'ENV', 'IFS', 'LD_PRELOAD', 'LD_LIBRARY_PATH', 'LD_AUDIT'], true)
                || strlen($value) > 8192 || str_contains($value, "\0")) {
                throw new SandboxError(t('sandbox.streams.bad_env'));
            }
        }
    }

    /** @return array<string, string> the minimal environment every command of the participant starts with */
    public function baseEnv(int $principalId): array {
        return [
            'HOME' => $this->workspace->home($principalId),
            'USER' => $this->workspace->user($principalId),
            'PATH' => self::PATH,
            'LANG' => 'C.UTF-8',
        ];
    }

    /**
     * The same wrapper for a long-lived process, without `env -i`: its environment is given to the process itself
     * (a token must not show up in the arguments, which other users can read in /proc).
     *
     * @return list<string>
     */
    public function streamArgv(int $principalId, SandboxLimits $limits, string $cwd): array {
        $argv = $this->argv($principalId, $limits, $cwd);
        $env = array_search('env', $argv, true);
        $setsid = array_search('setsid', $argv, true);
        if (!is_int($env) || !is_int($setsid)) {
            return $argv;
        }
        return [...array_slice($argv, 0, $env), ...array_slice($argv, $setsid)];
    }

    /**
     * What the shell of the wrapper reads first: the command up to a NUL, then the stdin of the command itself. The
     * command is not an argument: arguments of every process are seen by other users in /proc.
     *
     * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the command goes to the shell on stdin
     * @throws SandboxError a command with a NUL in it
     */
    public static function input(string $command, ?string $stdin = null): string {
        if (str_contains($command, "\0")) {
            throw new SandboxError(t('sandbox.command_runner.nul'), 400);
        }
        return $command . "\0" . ($stdin ?? '');
    }

    /**
     * The wrapper: the participant's shell reads the command from its stdin (see input()), enters $cwd and runs it.
     *
     * @param string $cwd directory the participant's shell enters before the command (umask 077: new files are private)
     * @return list<string>
     */
    public function argv(int $principalId, SandboxLimits $limits, string $cwd): array {
        $uid = (string) $this->workspace->uid($principalId);
        return [
            'prlimit',
            '--nproc=' . $limits->processes,
            '--cpu=' . $limits->cpuSeconds,
            '--as=' . $limits->memoryBytes,
            // No single file may outgrow the participant's disk limit while a command runs.
            '--fsize=' . $limits->diskBytes,
            '--',
            'setpriv', '--reuid=' . $uid, '--regid=' . $uid, '--clear-groups', '--',
            'env', '-i',
            'HOME=' . $this->workspace->home($principalId),
            'USER=' . $this->workspace->user($principalId),
            'PATH=' . self::PATH,
            'LANG=C.UTF-8',
            'setsid', '--wait',
            'bash', '-c', t('sandbox.command_runner.umask_077_cd_1_echo'),
            'mercurius', $cwd,
        ];
    }
}
