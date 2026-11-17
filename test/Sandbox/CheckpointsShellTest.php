<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use Amp\Cancellation;
use Amp\NullCancellation;
use OpenCCK\App\Agent\Sandbox\Checkpoints;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * The commands of the snapshots run in a real sandbox as the participant: a snapshot, a change, a restore of one file
 * and of the snapshot taken before the restore.
 */
final class CheckpointsShellTest extends SandboxTestCase {
    public function testASnapshotIsTakenComparedAndRestored(): void {
        $sandbox = $this->shell();
        $checkpoints = new Checkpoints($sandbox);
        $limits = self::limits();
        $cancel = new NullCancellation();
        self::assertSame(0, $this->sh(7, 'mkdir -p src inbox && echo one > src/a.txt && echo big > inbox/photo.jpg')->exitCode);

        $checkpoints->ensure(7, $limits, 1, $cancel);
        $first = trim(explode(' ', trim($checkpoints->list(7, $limits, $cancel)->stdout))[0]);
        self::assertTrue(Checkpoints::validId($first), 'a snapshot is listed: ' . $first);
        self::assertSame(0, $this->sh(7, 'echo two > src/a.txt')->exitCode);

        self::assertStringContainsString('src/a.txt', $checkpoints->diff(7, $limits, $first, $cancel)->stdout);
        self::assertSame(0, $checkpoints->restore(7, $limits, $first, 'src/a.txt', $cancel)->exitCode);
        self::assertSame("one\n", $this->sh(7, 'cat src/a.txt')->stdout);
        self::assertStringNotContainsString('inbox', $this->sh(7, 'git --git-dir=.checkpoints ls-tree -r --name-only HEAD')->stdout, 'incoming files are not in snapshots');

        $before = trim(explode(' ', trim($checkpoints->list(7, $limits, $cancel)->stdout))[0]);
        self::assertNotSame($first, $before, 'the restore kept what was there first');
        self::assertSame(0, $checkpoints->restore(7, $limits, $before, 'src/a.txt', $cancel)->exitCode);
        self::assertSame("two\n", $this->sh(7, 'cat src/a.txt')->stdout, 'and the restore is undone');
    }

    /** The sandbox interface over the executor's command runner, for exec only. */
    private function shell(): Sandbox {
        $sh = fn(int $id, string $command, int $timeout): \OpenCCK\Sandbox\CommandResult => $this->sh($id, $command, max(1, $timeout));
        return new class ($sh) implements Sandbox {
            /** @param \Closure(int, string, int): \OpenCCK\Sandbox\CommandResult $sh */
            public function __construct(private readonly \Closure $sh) {}

            public function exec(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, Cancellation $cancellation): CommandOutcome {
                $result = ($this->sh)($principalId, $command, $timeoutSeconds);
                return new CommandOutcome($result->exitCode, $result->stdout, $result->stderr, $result->timedOut, $result->overQuota, $result->durationMs);
            }

            public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines, Cancellation $cancellation): array {
                throw new \LogicException('not used');
            }

            public function write(int $principalId, SandboxLimits $limits, string $path, string $content, Cancellation $cancellation): void {
                throw new \LogicException('not used');
            }

            public function put(int $principalId, SandboxLimits $limits, string $path, string $bytes, Cancellation $cancellation): void {
                throw new \LogicException('not used');
            }

            public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new, Cancellation $cancellation): void {
                throw new \LogicException('not used');
            }

            public function list(int $principalId, SandboxLimits $limits, string $path, int $depth, Cancellation $cancellation): array {
                throw new \LogicException('not used');
            }

            public function fetch(int $principalId, SandboxLimits $limits, string $path, int $maxBytes, Cancellation $cancellation): string {
                throw new \LogicException('not used');
            }
        };
    }
}
