<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\ByteStream\StreamException;
use Amp\Future;
use Amp\Process\Process;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * One long-lived process of a participant: its stdin stays open for writes, its stdout goes into a bounded buffer the
 * client polls, its stderr into a window kept for the end. stdout and stderr are read at the same time, each in its
 * own fiber: a process that fills one pipe while we wait on the other would hang.
 *
 * spec: brief/agent-loop.md#песочница — the streaming API of the executor
 * ref: .claude/skills/skill-amphp/references/class-examples.md (amphp/process) — getStdin/getStdout/getStderr streams,
 *      join(); here they are read concurrently and the stdin is never ended while the session lives
 */
final class StreamSession {
    public float $lastUsed;
    private ?int $exitCode = null;
    private ?Process $process;
    private readonly StreamBuffer $stdout;
    private readonly OutputWindow $stderr;
    /** @var list<Future<void>> */
    private array $fibers = [];

    /**
     * @param list<string> $argv
     * @param array<string, string> $env the whole environment of the process
     * @param positive-int $bufferBytes
     */
    public function __construct(
        public readonly string $id,
        public readonly int $principalId,
        array $argv,
        string $cwd,
        array $env,
        int $bufferBytes,
        private readonly ProcessTree $tree = new ProcessTree(),
        /** what the process reads first: the command for the wrapper's shell */
        string $prelude = '',
    ) {
        $this->lastUsed = microtime(true);
        $this->stdout = new StreamBuffer($bufferBytes);
        $this->stderr = new OutputWindow(4_000, 12_000);
        $this->process = Process::start($argv, $cwd, $env);
        if ($prelude !== '') {
            $this->process->getStdin()->write($prelude);
        }
        $proc = $this->process;
        $stdout = $this->stdout;
        $stderr = $this->stderr;
        $this->fibers = [
            async(static function () use ($proc, $stdout): void {
                try {
                    while (($chunk = $proc->getStdout()->read()) !== null) {
                        $stdout->append($chunk);
                    }
                } finally {
                    $stdout->close();
                }
            }),
            async(static function () use ($proc, $stderr): void {
                while (($chunk = $proc->getStderr()->read()) !== null) {
                    $stderr->append($chunk);
                }
            }),
            async(function () use ($proc): void {
                $this->exitCode = $proc->join();
            }),
        ];
        foreach ($this->fibers as $fiber) {
            $fiber->ignore();
        }
    }

    /** @throws SandboxError when the process no longer reads */
    public function send(string $data): void {
        $this->lastUsed = microtime(true);
        try {
            $this->process?->getStdin()->write($data);
        } catch (StreamException) {
            throw new SandboxError(t('sandbox.streams.ended'), 409);
        }
    }

    /**
     * Output since the last call; waits up to $wait seconds for some. The end is reported once the output is taken:
     * exited, the exit code and the tail of stderr.
     *
     * @param positive-int $max
     * @return array{stdout: string, exited: bool, exit_code: ?int, stderr: string}
     */
    public function receive(int $max, float $wait): array {
        $this->lastUsed = microtime(true);
        $stdout = $this->stdout->take($max, $wait);
        $exited = $stdout === '' && $this->stdout->ended();
        if ($exited && $this->exitCode === null) {
            // the pipe closed first: the exit code follows a moment later
            try {
                $this->exitCode = $this->process?->join(new \Amp\TimeoutCancellation(2));
            } catch (Throwable) {
                // still running with its stdout closed; reported as ended anyway
            }
        }
        return ['stdout' => $stdout, 'exited' => $exited, 'exit_code' => $exited ? $this->exitCode : null, 'stderr' => $exited ? $this->stderr->text() : ''];
    }

    public function running(): bool {
        return !$this->stdout->ended();
    }

    /** Kills the session with everything it started and closes the pipes. */
    public function stop(): void {
        $process = $this->process;
        if ($process === null) {
            return;
        }
        $this->process = null;
        try {
            $this->tree->killSession($process->getPid());
            if ($process->isRunning()) {
                $process->kill();
            }
        } catch (Throwable) {
            // already gone
        }
        $process->getStdin()->close();
        $process->getStdout()->close();
        $process->getStderr()->close();
        $this->stdout->close();
    }
}
