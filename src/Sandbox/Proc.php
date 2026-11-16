<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\NullCancellation;
use Amp\Process\Process;
use Amp\TimeoutCancellation;

use function Amp\async;
use function Amp\Future\await;

/**
 * Starts a process, feeds stdin, reads stdout and stderr into bounded windows, and on timeout or cancellation kills
 * its whole session.
 */
final class Proc {
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
        ];
        $stdinStream = $process->getStdin();
        if ($stdin !== null && $stdin !== '') {
            $stdinStream->write($stdin);
        }
        $stdinStream->end();

        $timeout = new TimeoutCancellation($timeoutSeconds);
        $timedOut = false;
        $cancelled = false;
        try {
            $exitCode = $process->join(new CompositeCancellation($cancellation, $timeout));
        } catch (CancelledException) {
            $timedOut = !$cancellation->isRequested();
            $cancelled = !$timedOut;
            $this->tree->killSession($process->getPid());
            $exitCode = $process->join();
        }
        await($readers);

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
