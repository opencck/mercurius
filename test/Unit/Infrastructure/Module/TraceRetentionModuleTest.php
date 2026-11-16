<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Module;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Infrastructure\Module\TraceRetentionModule;
use OpenCCK\Test\Support\InMemoryTraceRepository;
use Psr\Log\NullLogger;

use function Amp\delay;

final class TraceRetentionModuleTest extends AsyncTestCase {
    public function testOldPayloadsArePurged(): void {
        $traces = new InMemoryTraceRepository();
        $run = $traces->openRun(1, 'm');
        $old = $traces->record(new TraceSpan(1, $run, TraceSpan::MODEL, 'm', new DateTimeImmutable(), 1, TraceSpan::OK));
        $fresh = $traces->record(new TraceSpan(1, $run, TraceSpan::MODEL, 'm', new DateTimeImmutable(), 1, TraceSpan::OK));
        $traces->payload($old, ['a' => 1], ['b' => 2]);
        $traces->payload($fresh, ['a' => 1], ['b' => 2]);
        $traces->payloads[$old]['at'] = new DateTimeImmutable('-15 days');
        $module = new TraceRetentionModule($traces, 14, new NullLogger());

        $module->start();
        delay(0.01);
        $module->stop();

        self::assertNull($traces->payloadOf($old));
        self::assertNotNull($traces->payloadOf($fresh));
    }
}
