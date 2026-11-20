<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Logger;

use Amp\PHPUnit\AsyncTestCase;
use Monolog\Level;
use Monolog\LogRecord;
use OpenCCK\Infrastructure\Logger\RunContext;

use function Amp\async;

/**
 * Every log line of a run carries its number: the fiber of the run and the fiber of each of its tool calls set it.
 *
 * spec: brief/operations.md#как-сделано-трассы-и-метрики
 */
final class RunContextTest extends AsyncTestCase {
    public function testALineWrittenInTheFiberOfARunNamesTheRun(): void {
        $line = static fn(): LogRecord => (new RunContext())(new LogRecord(new \DateTimeImmutable(), 'mercurius', Level::Info, 'x'));

        /** @var LogRecord $inRun */
        $inRun = async(static function () use ($line): LogRecord {
            RunContext::set(42);
            return $line();
        })->await();
        /** @var LogRecord $outside */
        $outside = async($line)->await();

        self::assertSame(42, $inRun->extra['run'] ?? null);
        self::assertArrayNotHasKey('run', $outside->extra, 'another fiber is not that run');
    }
}
