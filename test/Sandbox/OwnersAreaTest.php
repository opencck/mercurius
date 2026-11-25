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
    public function testOwnersAndTheMemberShareTheMembersFiles(): void {
        $this->files->write(7, self::limits(), 'note.txt', 'private');

        self::assertSame('private', $this->files->read(Workspace::OWNERS, self::limits(), 'p7/note.txt', 1, 10)['text']);
        $this->files->write(Workspace::OWNERS, self::limits(), 'p7/from-owner.txt', 'hello');
        $made = $this->runner->run(Workspace::OWNERS, self::limits(), 'mkdir -p p7/dir && echo x > p7/dir/made.txt && id -u', $this->workspace->home(Workspace::OWNERS), 30, null, new NullCancellation());

        self::assertSame("19999\n", $made->stdout, 'not root');
        self::assertSame('hello', $this->files->read(7, self::limits(), 'from-owner.txt', 1, 10)['text'], 'the member reads what the owners made');
        $this->files->write(7, self::limits(), 'dir/made.txt', 'changed');
        self::assertSame('changed', $this->files->read(Workspace::OWNERS, self::limits(), 'p7/dir/made.txt', 1, 10)['text']);
    }

    /** a link the member plants never opens what the owners could not open themselves */
    public function testALinkOfTheMemberLeadsTheOwnersNowhere(): void {
        $place = $this->workspace->root . '/../owners-secret-' . bin2hex(random_bytes(4));
        file_put_contents($place, 'root only');
        $secret = realpath($place);
        self::assertIsString($secret);
        chmod($secret, 0600);
        $this->runner->run(7, self::limits(), 'ln -s ' . escapeshellarg($secret) . ' link', $this->workspace->home(7), 30, null, new NullCancellation());

        $read = $this->runner->run(Workspace::OWNERS, self::limits(), 'cat p7/link; echo x >> p7/link; echo x >> /etc/ld.so.preload; echo done', $this->workspace->home(Workspace::OWNERS), 30, null, new NullCancellation());

        self::assertStringNotContainsString('root only', $read->stdout);
        self::assertSame('root only', file_get_contents($secret), 'not written through the link');
        self::assertFileDoesNotExist('/etc/ld.so.preload');
        unlink($secret);
    }

    public function testAnotherMemberStillCannotReadTheFolder(): void {
        $this->files->write(7, self::limits(), 'note.txt', 'private');
        $this->files->write(Workspace::OWNERS, self::limits(), 'p7/from-owner.txt', 'hello');

        $other = $this->runner->run(8, self::limits(), 'cat ../p7/note.txt ../p7/from-owner.txt; echo rc=$?', $this->workspace->home(8), 30, null, new NullCancellation());

        self::assertStringNotContainsString('private', $other->stdout);
        self::assertStringNotContainsString('hello', $other->stdout);
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
