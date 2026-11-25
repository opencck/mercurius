<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use Amp\NullCancellation;
use OpenCCK\Sandbox\Workspace;

/**
 * The owners' area: the root, by the user owners with the override of file rights only. A file they make in a
 * member's folder becomes the member's; the members' processes stay hidden and unkillable for them.
 *
 * spec: brief/agent-loop.md#раскладка-каталогов
 *
 * @group sandbox
 */
final class OwnersAreaTest extends SandboxTestCase {
    public function testOwnersReadAndWriteAMembersFolderAndTheFileIsHandedOver(): void {
        $this->files->write(7, self::limits(), 'note.txt', 'private');

        self::assertSame('private', $this->files->read(Workspace::OWNERS, self::limits(), 'p7/note.txt', 1, 10)['text']);
        $this->files->write(Workspace::OWNERS, self::limits(), 'p7/from-owner.txt', 'hello');
        $made = $this->runner->run(Workspace::OWNERS, self::limits(), 'echo x > p7/made.txt && id -u', $this->workspace->home(Workspace::OWNERS), 30, null, new NullCancellation());

        self::assertSame("19999\n", $made->stdout, 'not root');
        self::assertSame(20007, fileowner($this->workspace->home(7) . '/from-owner.txt'), 'handed over to the member');
        self::assertSame(20007, fileowner($this->workspace->home(7) . '/made.txt'));
        self::assertSame('hello', $this->files->read(7, self::limits(), 'from-owner.txt', 1, 10)['text'], 'the member reads it');
    }

    public function testOwnersMayNotSignalAMembersProcess(): void {
        $this->files->write(7, self::limits(), 'x', 'x');
        $sleep = $this->runner->run(7, self::limits(), 'sleep 20 & echo $!', $this->workspace->home(7), 30, null, new NullCancellation());
        $pid = trim($sleep->stdout);

        $kill = $this->runner->run(Workspace::OWNERS, self::limits(), 'kill ' . $pid . ' 2>&1; echo rc=$?', $this->workspace->home(Workspace::OWNERS), 30, null, new NullCancellation());

        self::assertStringNotContainsString('rc=0', $kill->stdout, 'the override of file rights is not a right over processes');
        $this->runner->run(7, self::limits(), 'kill ' . $pid, $this->workspace->home(7), 30, null, new NullCancellation());
    }
}
