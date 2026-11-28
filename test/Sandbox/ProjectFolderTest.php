<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use OpenCCK\Sandbox\Workspace;

/**
 * A project works by a user of its own in its folder inside its owner's: it reaches nothing above, the owner and the
 * owners reach its files.
 *
 * spec: brief/agent-loop.md#папка-контекста
 *
 * @group sandbox
 */
final class ProjectFolderTest extends SandboxTestCase {
    public function testAProjectStaysInItsFolderAndItsOwnerSeesIt(): void {
        $project = Workspace::projectHolder(12, 7);
        $this->files->write(7, self::limits(), 'private.txt', 'owner only');
        $this->files->write(7, self::limits(), 'projects/12/brief.md', 'made by the owner');

        $this->files->write($project, self::limits(), 'notes.md', 'made by the project');
        $inside = $this->sh($project, 'id -u; pwd; cat brief.md');
        $above = $this->sh($project, 'cat ../../private.txt; ls ../..; ls ..; echo x > ../../planted.txt; echo rc=$?');

        self::assertSame("3000012\n" . $this->workspace->home($project) . "\nmade by the owner", trim($inside->stdout), 'its own user, its folder, the owner\'s file in it');
        self::assertStringNotContainsString('owner only', $above->stdout, 'the owner\'s other files are closed');
        self::assertStringNotContainsString('private.txt', $above->stdout, 'the owner\'s folder is not listed');
        self::assertFileDoesNotExist($this->workspace->home(7) . '/planted.txt');
        self::assertSame('made by the project', $this->files->read(7, self::limits(), 'projects/12/notes.md', 1, 10)['text'], 'the owner reads what the project made');
        self::assertSame('made by the project', $this->files->read(Workspace::OWNERS, self::limits(), 'p7/projects/12/notes.md', 1, 10)['text'], 'and the owners too');
        $this->files->write(7, self::limits(), 'projects/12/notes.md', 'changed by the owner');
        self::assertSame('changed by the owner', $this->files->read($project, self::limits(), 'notes.md', 1, 10)['text']);
    }

    public function testAnotherMemberAndAnotherProjectStayOut(): void {
        $this->files->write(Workspace::projectHolder(12, 7), self::limits(), 'notes.md', 'twelve');

        $member = $this->sh(8, 'cat ../p7/projects/12/notes.md; echo rc=$?');
        $other = $this->sh(Workspace::projectHolder(13, 7), 'cat ../12/notes.md; echo rc=$?');

        self::assertStringNotContainsString('twelve', $member->stdout);
        self::assertStringNotContainsString('twelve', $other->stdout, 'a project of the same owner does not see its neighbour');
    }

    public function testAHandedProjectWorksInItsNewOwnersFolder(): void {
        $this->files->write(Workspace::projectHolder(14, 7), self::limits(), 'a.txt', 'before');
        // the app moves the folder on a transfer, unpacking it as the new owner; here, by hand, as root
        $this->files->write(8, self::limits(), 'projects/14/a.txt', 'before');
        $this->sh(Workspace::OWNERS, 'rm -rf p7/projects/14');

        $moved = $this->sh(Workspace::projectHolder(14, 8), 'pwd; cat a.txt; echo after > b.txt; echo rc=$?');

        self::assertStringContainsString($this->workspace->home(8) . '/projects/14', $moved->stdout);
        self::assertStringContainsString('rc=0', $moved->stdout);
    }
}
