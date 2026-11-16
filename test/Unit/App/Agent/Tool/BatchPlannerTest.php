<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\Tool\BatchPlanner;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Test\Support\FakeTool;
use PHPUnit\Framework\TestCase;

final class BatchPlannerTest extends TestCase {
    private static function call(string $id, DangerClass $danger, ?string $error = null): ValidatedCall {
        return new ValidatedCall(new ToolCall($id, 'fake'), new FakeTool(), $error === null ? [] : null, $error, $danger);
    }

    /**
     * @param list<list<ValidatedCall>> $segments
     * @return list<list<string>>
     */
    private static function ids(array $segments): array {
        return array_map(static fn(array $s) => array_map(static fn(ValidatedCall $c) => $c->call->id, $s), $segments);
    }

    public function testReadsFormOneSegmentWritesAreBarriers(): void {
        $segments = (new BatchPlanner())->segments([
            self::call('r1', DangerClass::Read),
            self::call('r2', DangerClass::Read),
            self::call('w1', DangerClass::Write),
            self::call('r3', DangerClass::Read),
        ]);

        self::assertSame([['r1', 'r2'], ['w1'], ['r3']], self::ids($segments));
    }

    public function testParallelSegmentIsCappedAt8(): void {
        $calls = [];
        for ($i = 1; $i <= 10; $i++) {
            $calls[] = self::call('r' . $i, DangerClass::Read);
        }
        $segments = (new BatchPlanner())->segments($calls);

        self::assertSame([8, 2], array_map('count', $segments));
    }

    public function testInvalidCallsKeepTheirPlace(): void {
        $segments = (new BatchPlanner())->segments([
            self::call('r1', DangerClass::Read),
            self::call('bad', DangerClass::Read, 'error'),
            self::call('r2', DangerClass::Read),
        ]);

        self::assertSame([['r1'], ['bad'], ['r2']], self::ids($segments));
    }
}
