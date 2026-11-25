<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Sandbox\CommandRunner;
use OpenCCK\Sandbox\Files;
use OpenCCK\Sandbox\Users;
use OpenCCK\Sandbox\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/agent-loop.md#раскладка-каталогов — the owners read and write the members' files, but not as root
 */
final class CommandArgvTest extends TestCase {
    private static function runner(): CommandRunner {
        $workspace = new Workspace();
        $users = new Users($workspace);
        return new CommandRunner($workspace, $users, new Files($workspace, $users));
    }

    public function testAMembersCommandHasNoCapabilities(): void {
        $argv = self::runner()->argv(7, new SandboxLimits(100, 10, 5, 0, 1), '/workspace/p7');

        self::assertContains('--reuid=20007', $argv);
        self::assertSame([], array_values(array_filter($argv, static fn(string $a): bool => str_contains($a, 'caps'))));
    }

    /** spec: brief/agent-loop.md#раскладка-каталогов — the owners reach the members' files by ACL, the kernel checks every one */
    public function testAnOwnersCommandHasNoCapabilitiesEither(): void {
        $argv = self::runner()->argv(Workspace::OWNERS, new SandboxLimits(100, 10, 5, 0, 1), '/workspace');

        self::assertContains('--reuid=19999', $argv);
        self::assertContains('HOME=/workspace', $argv);
        self::assertSame([], array_values(array_filter($argv, static fn(string $a): bool => str_contains($a, 'caps'))), 'no capability: a link to a file of root gives nothing');
    }
}
