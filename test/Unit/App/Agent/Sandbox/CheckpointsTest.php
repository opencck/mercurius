<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Sandbox;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\Sandbox\Checkpoints;
use OpenCCK\App\Agent\Tool\Files\CheckpointTool;
use OpenCCK\App\Agent\Tool\Files\WriteFileTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;

/**
 * Snapshots of the working folder: one before the first change of a run, a list, a diff, a restore that can be undone.
 */
final class CheckpointsTest extends AsyncTestCase {
    private FakeSandbox $sandbox;

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
    }

    private static function context(int $run = 3): ToolContext {
        return new ToolContext($run, 9, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    /** @return list<string> */
    private function commands(): array {
        return array_values(array_filter($this->sandbox->calls, static fn(string $c): bool => str_starts_with($c, 'exec')));
    }

    public function testASnapshotBeforeTheFirstChangeOfARunOnly(): void {
        $checkpoints = new Checkpoints($this->sandbox);
        $executor = new ToolExecutor(sandbox: $this->sandbox, checkpoints: $checkpoints);
        $write = new WriteFileTool($this->sandbox);
        $call = static fn(string $path): ValidatedCall => new ValidatedCall(new ToolCall('c-' . $path, 'write_file', '{}'), $write, ['path' => $path, 'content' => 'x'], null, DangerClass::Write);

        $executor->run($call('a.txt'), self::context(), 10);
        $executor->run($call('b.txt'), self::context(), 10);
        $executor->run($call('c.txt'), self::context(4), 10);

        $snapshots = array_values(array_filter($this->commands(), static fn(string $c): bool => str_contains($c, 'commit')));
        self::assertCount(2, $snapshots, 'one per run, before its first change');
        self::assertStringContainsString('.checkpoints', $snapshots[0]);
        self::assertStringContainsString('inbox/', $snapshots[0], 'incoming files are not copied into snapshots');
        $first = array_search($snapshots[0], $this->sandbox->calls, true);
        $written = array_search('write 1 a.txt', $this->sandbox->calls, true);
        self::assertIsInt($first);
        self::assertIsInt($written);
        self::assertLessThan($written, $first, 'the snapshot comes before the change');
    }

    public function testASkillWrittenIntoTheFolderGetsASnapshotFirst(): void {
        $checkpoints = new Checkpoints($this->sandbox);
        $executor = new ToolExecutor(sandbox: $this->sandbox, checkpoints: $checkpoints);
        $save = new class implements \OpenCCK\App\Agent\Tool\Tool, \OpenCCK\App\Agent\Tool\Sandbox\ChangesSandbox {
            public function definition(): \Mercurius\Llm\ToolDefinition {
                return new \Mercurius\Llm\ToolDefinition('skill_save', 'x', ['type' => 'object']);
            }

            public function danger(array $args): DangerClass {
                return DangerClass::Write;
            }

            public function untrusted(): bool {
                return false;
            }

            public function timeout(): int {
                return 10;
            }

            public function execute(array $args, \OpenCCK\App\Agent\Tool\ToolContext $context): \OpenCCK\App\Agent\Tool\ToolResult {
                return \OpenCCK\App\Agent\Tool\ToolResult::ok('saved');
            }
        };

        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — a snapshot before a skill is written
        $executor->run(new ValidatedCall(new ToolCall('s1', 'skill_save', '{}'), $save, [], null, DangerClass::Write), self::context(), 10);

        self::assertCount(1, array_filter($this->commands(), static fn(string $c): bool => str_contains($c, 'commit')));
    }

    public function testReadsTakeNoSnapshot(): void {
        $checkpoints = new Checkpoints($this->sandbox);
        $executor = new ToolExecutor(sandbox: $this->sandbox, checkpoints: $checkpoints);
        $read = new \OpenCCK\App\Agent\Tool\Files\ReadFileTool($this->sandbox);
        $this->sandbox->files[1]['a.txt'] = 'x';

        $executor->run(new ValidatedCall(new ToolCall('c1', 'read_file', '{}'), $read, ['path' => 'a.txt'], null, DangerClass::Read), self::context(), 10);

        self::assertSame([], $this->commands());
    }

    public function testTheToolListsDiffsAndRestoresAfterASnapshotOfNow(): void {
        $tool = new CheckpointTool($this->sandbox, new Checkpoints($this->sandbox));

        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'list']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'restore', 'id' => 'abc1234']));
        $tool->execute(['action' => 'list'], self::context());
        self::assertStringContainsString('log', $this->commands()[0]);

        $result = $tool->execute(['action' => 'restore', 'id' => 'abc1234', 'path' => 'src/app.php'], self::context());
        self::assertFalse($result->isError, $result->content);
        $commands = $this->commands();
        self::assertStringContainsString('commit', $commands[1], 'what is there now is kept first: the restore can be undone');
        self::assertStringContainsString("checkout 'abc1234' -- 'src/app.php'", $commands[2]);
    }

    public function testSnapshotsRunWithoutSettingsThatStartPrograms(): void {
        $tool = new CheckpointTool($this->sandbox, new Checkpoints($this->sandbox));

        $tool->execute(['action' => 'restore', 'id' => 'abc1234', 'path' => 'a.txt'], self::context());
        $tool->execute(['action' => 'list'], self::context());

        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — the participant writes the folder of the snapshots too: its
        //       config is written anew before every command, and git takes no settings from elsewhere
        foreach ($this->commands() as $command) {
            self::assertStringContainsString('GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null git -c core.fsmonitor=false -c core.hooksPath=/dev/null -c diff.external= -c core.pager=cat', $command);
            self::assertStringContainsString("> .checkpoints/config", $command, 'filters and the like of the old config are gone');
        }
    }

    public function testABadIdOrPathIsRefused(): void {
        $tool = new CheckpointTool($this->sandbox, new Checkpoints($this->sandbox));

        self::assertTrue($tool->execute(['action' => 'restore', 'id' => 'abc; rm -rf /'], self::context())->isError);
        self::assertTrue($tool->execute(['action' => 'restore', 'id' => 'abc1234', 'path' => '../etc/passwd'], self::context())->isError);
        self::assertTrue($tool->execute(['action' => 'diff', 'id' => '--output=x'], self::context())->isError);
        self::assertSame([], $this->commands());
    }
}
