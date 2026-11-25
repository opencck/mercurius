<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use OpenCCK\Sandbox\SandboxError;
use OpenCCK\Sandbox\Workspace;
use PHPUnit\Framework\TestCase;

final class WorkspaceTest extends TestCase {
    public function testParticipantNaming(): void {
        $workspace = new Workspace();

        self::assertSame('p7', $workspace->user(7));
        self::assertSame(20007, $workspace->uid(7));
        self::assertSame('/workspace/p7', $workspace->home(7));
    }

    /** spec: brief/agent-loop.md#раскладка-каталогов — the owners' area is the root, worked in by a user of its own */
    public function testTheOwnersAreaIsTheRoot(): void {
        $workspace = new Workspace();

        self::assertSame(['owners', 19999, '/workspace'], [$workspace->user(Workspace::OWNERS), $workspace->uid(Workspace::OWNERS), $workspace->home(Workspace::OWNERS)]);
        self::assertSame('/workspace/p8/x', $workspace->resolve(Workspace::OWNERS, 'p8/x'), 'a member\'s folder from the root');
        self::assertSame('/workspace', $workspace->resolve(Workspace::OWNERS, '.'));
        $this->expectException(SandboxError::class);
        $workspace->resolve(Workspace::OWNERS, '../etc');
    }

    public function testResolveKeepsPathsInsideHome(): void {
        $workspace = new Workspace();

        self::assertSame('/workspace/p7/b', $workspace->resolve(7, 'a/../b'));
        self::assertSame('/workspace/p7', $workspace->resolve(7, '.'));
        self::assertSame('/workspace/p7', $workspace->resolve(7, ''));
        self::assertSame('/workspace/p7/x/y', $workspace->resolve(7, '/workspace/p7/x//y/'));
    }

    /** @return iterable<string, array{string}> */
    public static function escapes(): iterable {
        yield 'parent' => ['../p8/x'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'sibling' => ['/workspace/p8'];
        yield 'prefix trick' => ['/workspace/p77/x'];
        yield 'deep parent' => ['a/../../..'];
    }

    /** @dataProvider escapes */
    public function testResolveRejectsEscape(string $path): void {
        $this->expectException(SandboxError::class);
        $this->expectExceptionMessage('вне рабочего каталога');
        (new Workspace())->resolve(7, $path);
    }

    public function testResolveRejectsNul(): void {
        $this->expectException(SandboxError::class);
        (new Workspace())->resolve(7, "a\0b");
    }
}
