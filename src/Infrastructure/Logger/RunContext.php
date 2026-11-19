<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Logger;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Revolt\EventLoop\FiberLocal;

/**
 * The number of the run a log line was written in, as `extra.run`. The fiber of a run and the fiber of each of its tool
 * calls set it (a fiber-local value is not passed to the fibers it starts).
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — every log line of a run carries its number
 */
final class RunContext implements ProcessorInterface {
    /** @var FiberLocal<int>|null 0 — no run */
    private static ?FiberLocal $run = null;

    public static function set(int $runId): void {
        self::local()->set($runId);
    }

    public function __invoke(LogRecord $record): LogRecord {
        $run = self::local()->get();
        return $run === 0 ? $record : $record->with(extra: $record->extra + ['run' => $run]);
    }

    /** @return FiberLocal<int> */
    private static function local(): FiberLocal {
        return self::$run ??= new FiberLocal(static fn(): int => 0);
    }
}
