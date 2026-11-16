<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\NullCancellation;
use Amp\Process\Process;
use Amp\TimeoutCancellation;

use function Amp\async;
use function Amp\Future\await;

/**
 * Starts a process, feeds stdin, reads stdout and stderr into bounded windows. When the process ends, times out or is
 * cancelled, its whole session is killed, so background jobs never outlive the command or hold its pipes; every
 * wait afterwards is bounded.
 */
final class Proc {
    /** Seconds to wait for the pipes and the wrapper after the session is killed. */
    private const DRAIN_SECONDS = 2;

    public function __construct(
        private readonly ProcessTree $tree = new ProcessTree(),
        private readonly int $windowBytes = 25_000,
    ) {}

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @param positive-int $timeoutSeconds
     */
    public function run(array $argv, string $cwd, int $timeoutSeconds, ?string $stdin = null, ?Cancellation $cancellation = null, array $env = []): CommandResult {
        $started = microtime(true);
        $cancellation ??= new NullCancellation();
        $process = Process::start($argv, $cwd, $env);
        $stdout = new OutputWindow(max(1, $this->windowBytes), max(1, $this->windowBytes));
        $stderr = new OutputWindow(max(1, $this->windowBytes), max(1, $this->windowBytes));

        $readers = [
            async(static function () use ($process, $stdout): void {
                while (($chunk = $process->getStdout()->read()) !== null) {
                    $stdout->append($chunk);
                }
            }),
            async(static function () use ($process, $stderr): void {
                while (($chunk = $process->getStderr()->read()) !== null) {
                    $stderr->append($chunk);
                }
            }),
            // stdin is fed in its own fiber: a reader that never reads (a FIFO) must not hang us.
            async(static function () use ($process, $stdin): void {
                $input = $process->getStdin();
                try {
                    if ($stdin !== null && $stdin !== '') {
                        $input->write($stdin);
                    }
                    $input->end();
                } catch (StreamException) {
                    // The process stopped reading (exited early or was killed).
                }
            }),
        ];

        $timedOut = false;
        $cancelled = false;
        try {
            $exitCode = $process->join(new CompositeCancellation($cancellation, new TimeoutCancellation($timeoutSeconds)));
        } catch (CancelledException) {
            $timedOut = !$cancellation->isRequested();
            $cancelled = !$timedOut;
            $exitCode = 137;
        }

        // Background jobs of the command die with it: state lives in files, not in leftover processes.
        $this->tree->killSession($process->getPid());
        try {
            if ($process->isRunning()) {
                $exitCode = $timedOut || $cancelled ? 137 : $process->join(new TimeoutCancellation(self::DRAIN_SECONDS));
            }
            await($readers, new TimeoutCancellation(self::DRAIN_SECONDS));
        } catch (CancelledException) {
            // Something outside the session still holds a pipe: stop reading, keep what we have.
            $process->getStdout()->close();
            $process->getStderr()->close();
            $process->getStdin()->close();
            foreach ($readers as $reader) {
                $reader->ignore();
            }
        }

        return new CommandResult(
            $exitCode,
            $stdout->text(),
            $stderr->text(),
            $timedOut,
            $cancelled,
            (int) round((microtime(true) - $started) * 1000.0),
        );
    }
}
