<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Skill;

use OpenCCK\App\Skill\SkillFolders;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Test\Support\FakeSandbox;
use PHPUnit\Framework\TestCase;

/**
 * Skills move from skills/ to .mercurius/skills/ (and in a project's folder the same), the old folder kept as a backup.
 *
 * spec: brief/skills-mcp.md#скиллы-и-mcp — the layout of skills: .mercurius/skills of the participant and of the project
 */
final class SkillFoldersTest extends TestCase {
    public function testTheMoveCommandMovesOnceAndKeepsACopy(): void {
        $dir = sys_get_temp_dir() . '/skills-move-' . bin2hex(random_bytes(4));
        mkdir($dir . '/skills/deploy', 0777, true);
        file_put_contents($dir . '/skills/deploy/SKILL.md', "---\nname: deploy\n---\n");
        $command = SkillFolders::moveCommand('skills', '.mercurius/skills', 'skills-2026-11-19');
        $run = static function (string $command) use ($dir): string {
            $process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
            if (!is_resource($process)) {
                self::markTestSkipped('no sh');
            }
            $out = (string) stream_get_contents($pipes[1]);
            proc_close($process);
            return trim($out);
        };

        self::assertSame('moved', $run($command));
        self::assertFileExists($dir . '/.mercurius/skills/deploy/SKILL.md');
        self::assertDirectoryDoesNotExist($dir . '/skills');
        self::assertFileExists($dir . '/.mercurius/backup/skills-2026-11-19/deploy/SKILL.md', 'the old folder is kept');
        mkdir($dir . '/skills');
        self::assertSame('', $run($command), 'the new place is there: nothing is touched');
        self::assertDirectoryExists($dir . '/skills');
    }

    public function testEveryParticipantAndProjectIsMovedInItsOwnersSandbox(): void {
        $sandbox = new FakeSandbox();
        $limits = new SandboxLimits(1_000_000_000, 64, 60, 0, 2);
        $folders = new SkillFolders($sandbox, static fn(int $id): ?SandboxLimits => $id === 9 ? null : $limits, static fn(): string => '2026-11-19');

        $folders->migrate([1, 5, 9], [[12, 1], [13, 5]]);

        $commands = implode("\n", $sandbox->calls);
        self::assertStringContainsString('exec 1 ', $commands);
        self::assertStringContainsString('exec 5 ', $commands);
        self::assertStringNotContainsString('exec 9 ', $commands, 'no sandbox, nothing to move');
        self::assertStringContainsString("'projects/12/skills'", $commands);
        self::assertStringContainsString("'projects/12/.mercurius/skills'", $commands);
        self::assertStringContainsString("'projects/13/skills'", $commands);
    }
}
