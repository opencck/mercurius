<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use Amp\DeferredCancellation;
use OpenCCK\Sandbox\CommandResult;
use OpenCCK\Sandbox\SandboxError;

use function Amp\async;
use function Amp\delay;

/**
 * @group sandbox
 */
final class CommandRunnerTest extends SandboxTestCase {
    public function testCommandRunsAsParticipant(): void {
        $result = $this->sh(7, 'id -u; pwd');

        self::assertSame(0, $result->exitCode);
        self::assertSame("20007\n" . $this->workspace->home(7) . "\n", $result->stdout);
    }

    public function testOtherHomeIsUnreadable(): void {
        $this->sh(8, 'echo secret > f');
        $result = $this->sh(7, 'cat ' . $this->workspace->home(8) . '/f');

        self::assertNotSame(0, $result->exitCode);
        self::assertStringNotContainsString('secret', $result->stdout);
    }

    public function testEnvironmentIsClean(): void {
        putenv('SANDBOX_TOKEN=should-not-leak');
        $result = $this->sh(7, 'env');

        self::assertStringNotContainsString('SANDBOX_TOKEN', $result->stdout);
        self::assertStringContainsString('HOME=' . $this->workspace->home(7), $result->stdout);
    }

    public function testEnvironmentOfOneCommandReachesItButNotItsArguments(): void {
        $result = $this->runner->run(7, self::limits(), 'echo "$GIT_TOKEN_TEST"; tr "\0" " " < /proc/$$/cmdline', $this->workspace->home(7), 10, null, new \Amp\NullCancellation(), ['GIT_TOKEN_TEST' => 'tok-123']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        $lines = explode("\n", $result->stdout, 2);
        [$value, $cmdline] = [$lines[0], $lines[1] ?? ''];
        self::assertSame('tok-123', $value);
        self::assertStringNotContainsString('tok-123', $cmdline, 'the value is not in the arguments other users see');
        putenv('SANDBOX_TOKEN=should-not-leak');
        self::assertStringNotContainsString('SANDBOX_TOKEN', $this->runner->run(7, self::limits(), 'env', $this->workspace->home(7), 10, null, new \Amp\NullCancellation(), ['A_B' => '1'])->stdout);
    }

    public function testTimeoutKillsBackgroundProcesses(): void {
        $result = $this->sh(7, 'sleep 300 & nohup sleep 301 >/dev/null 2>&1 & sleep 302', 1);

        self::assertTrue($result->timedOut);
        delay(0.3);
        self::assertSame('', trim($this->sh(9, 'pgrep -u 20007 sleep || true')->stdout));
    }

    public function testCancelKillsCommand(): void {
        $deferred = new DeferredCancellation();
        $future = async(fn() => $this->runner->run(7, self::limits(), 'sleep 300', $this->workspace->home(7), 60, null, $deferred->getCancellation()));
        delay(0.3);
        $deferred->cancel();

        $result = $future->await();
        self::assertInstanceOf(CommandResult::class, $result);
        self::assertTrue($result->cancelled);
    }

    public function testProcessLimitStopsForkBomb(): void {
        $result = $this->sh(7, 'for i in $(seq 60); do sleep 3 & done; wait', 10, self::limits(processes: 20));

        self::assertStringContainsString('fork', $result->stderr . $result->stdout);
    }

    public function testConcurrentCommandsAreLimited(): void {
        $first = async(fn() => $this->sh(7, 'sleep 1', 5, self::limits(concurrent: 1)));
        delay(0.2);
        try {
            $this->sh(7, 'true', 5, self::limits(concurrent: 1));
            self::fail('No limit');
        } catch (SandboxError $e) {
            self::assertSame(429, $e->status);
        }
        $first->await();
    }

    /** Review C1: cwd through a symlink must not start the command inside another participant's tree. */
    public function testCwdThroughSymlinkCannotReachOtherHome(): void {
        $this->sh(8, 'mkdir -p project && echo secret > project/f && chmod 755 project && chmod 644 project/f');
        $this->sh(7, 'ln -s ' . $this->workspace->home(8) . '/project x');

        $result = $this->runner->run(7, self::limits(), 'cat f', $this->workspace->home(7) . '/x', 10, null, new \Amp\NullCancellation());

        self::assertNotSame(0, $result->exitCode);
        self::assertStringNotContainsString('secret', $result->stdout);
    }

    /** Review C2: a background job must not keep the command (and its slot) after the foreground part ends. */
    public function testBackgroundJobDoesNotHoldCommand(): void {
        $started = microtime(true);
        $result = $this->sh(7, 'sleep 300 & echo done', 10, self::limits(concurrent: 1));

        self::assertSame("done\n", $result->stdout);
        self::assertLessThan(5, microtime(true) - $started);
        self::assertSame(0, $this->sh(7, 'true', 10, self::limits(concurrent: 1))->exitCode);
    }

    /** Review I5: a single file cannot grow past the disk limit during a command. */
    public function testFileSizeIsLimitedDuringCommand(): void {
        $result = $this->sh(7, 'head -c 3000 /dev/zero > big; stat -c %s big', 10, self::limits(disk: 1024));

        self::assertSame('1024', trim($result->stdout));
    }

    /** Review I1 and M1: a new participant's first operations may run in parallel. */
    public function testParallelFirstUseCreatesTheUserOnce(): void {
        $futures = [];
        for ($i = 0; $i < 4; $i++) {
            $futures[] = async(fn() => $this->sh(11, 'id -u'));
        }
        foreach ($futures as $future) {
            $result = $future->await();
            self::assertInstanceOf(CommandResult::class, $result);
            self::assertSame("20011\n", $result->stdout);
        }
    }

    public function testOverQuotaAfterCommandIsReported(): void {
        $result = $this->sh(7, 'head -c 3000 /dev/zero > big', 10, self::limits(disk: 1024));

        self::assertTrue($result->overQuota);
    }
}
