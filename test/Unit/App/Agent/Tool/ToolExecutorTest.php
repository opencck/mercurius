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
use OpenCCK\Test\Support\FakeSandbox;
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

    private static function sandboxContext(bool $withSandbox = true): ToolContext {
        return new ToolContext(
            5,
            1,
            new Principal(7, PrincipalKind::Human, Role::Owner, null),
            new NullCancellation(),
            new \OpenCCK\Domain\Agent\ParticipantPolicy(null, new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120), $withSandbox ? new \OpenCCK\Domain\Agent\SandboxLimits(100, 10, 5, 1000, 1) : null, true),
            'call/1',
        );
    }

    public function testLargeResultIsSpilledWithPreviewAndPath(): void {
        $sandbox = new FakeSandbox();
        $tool = new FakeTool('big', handler: static fn(): ToolResult => ToolResult::ok(str_repeat('я', 60)));
        $result = (new ToolExecutor(maxResultChars: 50, sandbox: $sandbox, previewChars: 10))->run(self::call($tool), self::sandboxContext(), 5);

        $path = '.mercurius/results/5/call_1.txt';
        self::assertSame(str_repeat('я', 60), $sandbox->files[7][$path]);
        self::assertSame(str_repeat('я', 10) . "\n…\n[Полный результат (60 символов) сохранён: " . $path . '. Читай его через read_file.]', $result->content);
    }

    public function testReadFileIsNeverSpilled(): void {
        $sandbox = new FakeSandbox();
        $sandbox->files[7]['f'] = str_repeat("строка\n", 20);
        $call = new ValidatedCall(new ToolCall('c1', 'read_file'), new \OpenCCK\App\Agent\Tool\ReadFileTool($sandbox), ['path' => 'f'], null, DangerClass::Read);

        $result = (new ToolExecutor(maxResultChars: 50, sandbox: $sandbox))->run($call, self::sandboxContext(), 5);

        self::assertStringNotContainsString('сохранён', $result->content);
        self::assertSame(['read 7 f 1 500'], $sandbox->calls);
    }

    public function testSpillFailureFallsBackToTruncation(): void {
        $sandbox = new FakeSandbox();
        $sandbox->failure = 'Рабочий каталог заполнен';
        $tool = new FakeTool('big', handler: static fn(): ToolResult => ToolResult::ok(str_repeat('a', 60)));

        $result = (new ToolExecutor(maxResultChars: 50, sandbox: $sandbox))->run(self::call($tool), self::sandboxContext(), 5);

        self::assertStringEndsWith('[обрезано: показано 50 из 60 символов]', $result->content);
    }

    public function testNoSandboxFallsBackToTruncation(): void {
        $sandbox = new FakeSandbox();
        $tool = new FakeTool('big', handler: static fn(): ToolResult => ToolResult::ok(str_repeat('a', 60)));

        $result = (new ToolExecutor(maxResultChars: 50, sandbox: $sandbox))->run(self::call($tool), self::sandboxContext(false), 5);

        self::assertStringEndsWith('[обрезано: показано 50 из 60 символов]', $result->content);
        self::assertSame([], $sandbox->calls);
    }

    public function testNulBytesAreReplaced(): void {
        $tool = new FakeTool('nul', handler: static fn(): ToolResult => ToolResult::ok("a\0b"));
        $result = (new ToolExecutor())->run(self::call($tool), $this->context(), 5);

        self::assertSame("a\u{FFFD}b", $result->content);
    }

    public function testUntrustedResultIsWrapped(): void {
        $result = (new ToolExecutor())->run(self::call(new FakeTool('web', untrusted: true)), $this->context(), 5);

        self::assertStringStartsWith('<untrusted-data source="web">', $result->content);
    }
}
