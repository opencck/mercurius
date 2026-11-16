<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use OpenCCK\Sandbox\SandboxError;

/**
 * @group sandbox
 */
final class FilesTest extends SandboxTestCase {
    public function testWriteThenReadWithLineRange(): void {
        $this->files->write(7, self::limits(), 'dir/a.txt', "one\ntwo\nthree\n");
        $read = $this->files->read(7, self::limits(), 'dir/a.txt', 2, 1);

        self::assertSame("two\n", $read['text']);
        self::assertSame(3, $read['totalLines']);
        self::assertSame('20007', trim($this->sh(7, 'stat -c %u dir/a.txt')->stdout));
    }

    public function testSymlinkEscapeIsRefusedByKernel(): void {
        $this->files->write(8, self::limits(), 'f', 'secret');
        $this->sh(7, 'ln -s ' . $this->workspace->home(8) . '/f link');

        $this->expectException(SandboxError::class);
        $this->files->read(7, self::limits(), 'link', 1, 10);
    }

    public function testWriteBeyondQuotaIsRefused(): void {
        try {
            $this->files->write(7, self::limits(disk: 1024), 'big', str_repeat('x', 2048));
            self::fail('No quota error');
        } catch (SandboxError $e) {
            self::assertSame(413, $e->status);
        }
    }

    public function testReplaceNeedsExactlyOneMatch(): void {
        $this->files->write(7, self::limits(), 'r.txt', 'a b a');
        try {
            $this->files->replace(7, self::limits(), 'r.txt', 'a', 'c');
            self::fail('No error');
        } catch (SandboxError $e) {
            self::assertSame(409, $e->status);
        }
        $this->files->replace(7, self::limits(), 'r.txt', 'b', 'B');
        self::assertSame('a B a', $this->files->read(7, self::limits(), 'r.txt', 1, 10)['text']);
    }

    public function testListIsCapped(): void {
        $this->sh(7, 'mkdir many && cd many && for i in $(seq 600); do : > f$i; done');
        $entries = $this->files->list(7, self::limits(), 'many', 1);

        self::assertCount(500, $entries);
        self::assertSame('file', $entries[0]['type']);
    }

    public function testPathOutsideHomeIsRefused(): void {
        $this->expectException(SandboxError::class);
        $this->files->read(7, self::limits(), '/etc/passwd', 1, 10);
    }
}
