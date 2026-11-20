<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Delegation;

use OpenCCK\App\Agent\Delegation\WorkerReport;
use OpenCCK\App\Agent\Delegation\WorkerReports;
use PHPUnit\Framework\TestCase;

final class WorkerReportsTest extends TestCase {
    public function testTheShareOfEachPartFollowsTheRoomLeftInTheOrchestratorsWindow(): void {
        // spec: brief/orchestration.md#часть-1--синхронное-делегирование — min(4000, free room ÷ number of parts)
        $long = str_repeat('x', 10_000);
        $reports = [new WorkerReport(1, 11, 'done', 'm', 1, 10, $long), new WorkerReport(2, 12, 'done', 'm', 1, 10, $long)];

        $roomy = WorkerReports::text($reports, 100_000);
        $tight = WorkerReports::text($reports, 2_000);
        $none = WorkerReports::text($reports, 0);

        self::assertSame(2 * 4000, substr_count($roomy, 'x'), 'never above 4000 a part');
        self::assertSame(2 * 1000, substr_count($tight, 'x'), 'the room shared by the parts');
        self::assertSame(2 * 500, substr_count($none, 'x'), 'never under 500: the orchestrator must see something');
        self::assertSame(substr_count(WorkerReports::text($reports), 'x'), 2 * 4000, 'unknown room — the old fixed share');
    }
}
