<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class ToolExecutorTest extends AsyncTestCase {
    private function context(?\Amp\Cancellation $cancellation = null): ToolContext {
        return new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::Owner, null), $cancellation ?? new NullCancellation());
    }

    /** @param array<string, mixed> $args */
    private static function call(FakeTool $tool, array $args = []): ValidatedCall {
        return new ValidatedCall(new ToolCall('c1', $tool->definition()->name), $tool, $args, null, DangerClass::Read);
    }

    public function testResultIsReturned(): void {
        $result = (new ToolExecutor())->run(self::call(new FakeTool('t'), ['q' => 'x']), $this->context(), 5);

        self::assertSame('t:{"q":"x"}', $result->content);
        self::assertFalse($result->isError);
    }

    public function testTimeoutBecomesErrorResult(): void {
        $result = (new ToolExecutor())->run(self::call(new FakeTool('slow', delay: 2.0)), $this->context(), 1);

        self::assertTrue($result->isError);
        self::assertSame('Инструмент slow не уложился в 1 с.', $result->content);
    }

    public function testExceptionBecomesErrorResultWithoutMessage(): void {
        $tool = new FakeTool('boom', handler: static function (array $args): ToolResult {
            if ($args === []) {
                throw new RuntimeException('password=hunter2');
            }
            return ToolResult::ok('unreachable');
        });
        $result = (new ToolExecutor())->run(self::call($tool), $this->context(), 5);

        self::assertTrue($result->isError);
        self::assertSame('Инструмент boom завершился с ошибкой (RuntimeException).', $result->content);
    }

    public function testRunCancellationPropagates(): void {
        $deferred = new DeferredCancellation();
        $future = async(fn() => (new ToolExecutor())->run(self::call(new FakeTool('slow', delay: 2.0)), $this->context($deferred->getCancellation()), 10));
        delay(0.05);
        $deferred->cancel();

        $this->expectException(CancelledException::class);
        $future->await();
    }

    public function testLongResultIsTruncated(): void {
        $tool = new FakeTool('long', handler: static fn(): ToolResult => ToolResult::ok(str_repeat('я', 30)));
        $result = (new ToolExecutor(maxResultChars: 10))->run(self::call($tool), $this->context(), 5);

        self::assertSame(str_repeat('я', 10) . "\n… [обрезано: показано 10 из 30 символов]", $result->content);
    }

    public function testUntrustedResultIsWrapped(): void {
        $result = (new ToolExecutor())->run(self::call(new FakeTool('web', untrusted: true)), $this->context(), 5);

        self::assertStringStartsWith('<untrusted-data source="web">', $result->content);
    }
}
