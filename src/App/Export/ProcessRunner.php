<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\Process\Process;
use Closure;
use RuntimeException;

use function Amp\async;
use function Amp\ByteStream\buffer;

/**
 * Runs pg_dump, pg_restore, psql and tar for the archive of the agent: the arguments as a list (no shell), the
 * environment of this process with the extra variables over it, a non-zero exit as an error with the start of stderr.
 *
 * spec: brief/operations.md#экспорт-и-импорт-агента
 */
final class ProcessRunner {
    private function __construct(private readonly Cancellation $cancellation) {}

    /** @return Closure(list<string>, array<string, string>): void */
    public static function closure(Cancellation $cancellation = new NullCancellation()): Closure {
        return (new self($cancellation))->call(...);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function call(array $command, array $env): void {
        self::run($command, $env, $this->cancellation);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public static function run(array $command, array $env, Cancellation $cancellation): void {
        $process = Process::start($command, null, $env + getenv());
        $error = '';
        $reading = async(static function () use ($process, &$error): void {
            $error = buffer($process->getStderr());
        });
        buffer($process->getStdout());
        $code = $process->join($cancellation);
        $reading->await();
        if ($code !== 0) {
            throw new RuntimeException(sprintf('%s exited with %d: %s', $command[0] ?? '', $code, mb_substr(trim($error), 0, 300)));
        }
    }
}
