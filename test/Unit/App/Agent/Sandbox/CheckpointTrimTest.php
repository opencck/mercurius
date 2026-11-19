<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Sandbox;

use OpenCCK\App\Agent\Sandbox\Checkpoints;
use PHPUnit\Framework\TestCase;

/**
 * At most 20 snapshots of a working folder: the oldest go, the rest keep their files, messages and dates.
 *
 * spec: BRIEF.md#скиллы-и-mcp — the limit of snapshots, as hermes max_snapshots = 20
 */
final class CheckpointTrimTest extends TestCase {
    private string $dir = "";

    private function sh(string $command): string {
        $process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        if (!is_resource($process)) {
            self::markTestSkipped('no sh');
        }
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        return trim($out);
    }

    public function testOnlyTheNewestSnapshotsStay(): void {
        $this->dir = sys_get_temp_dir() . '/checkpoint-trim-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        if ($this->sh('command -v git') === '') {
            self::markTestSkipped('no git');
        }
        $g = Checkpoints::GIT;
        $this->sh("git init -q --bare --template= .checkpoints");
        for ($i = 1; $i <= 25; $i++) {
            $date = sprintf('2026-11-%02dT10:00:00+00:00', $i);
            $this->sh("echo $i > f && $g add -A && GIT_AUTHOR_DATE=$date GIT_COMMITTER_DATE=$date $g -c user.name=m -c user.email=m@m commit -q -m 'snap $i'");
        }
        self::assertSame('25', $this->sh("$g rev-list --count HEAD"));

        $this->sh(Checkpoints::trimScript(20));

        self::assertSame('20', $this->sh("$g rev-list --count HEAD"));
        self::assertStringStartsWith('snap 6|2026-11-06T10:00:00', $this->sh("$g log --reverse --format='%s|%cI' HEAD | head -n 1"), 'the oldest kept, with its date');
        self::assertSame('snap 25', $this->sh("$g log -1 --format=%s HEAD"));
        self::assertSame('25', $this->sh("$g show HEAD:f"), 'the files of a snapshot stay');
        $this->sh(Checkpoints::trimScript(20));
        self::assertSame('20', $this->sh("$g rev-list --count HEAD"), 'at the limit nothing changes');
    }
}
