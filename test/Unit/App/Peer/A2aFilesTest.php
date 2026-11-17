<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use OpenCCK\App\Peer\A2aFiles;
use OpenCCK\Test\Support\InMemoryA2aFileRepository;
use PHPUnit\Framework\TestCase;

/**
 * Files between agents: an upload ahead of a message, references only to own uploads, bytes in a part, the answer.
 */
final class A2aFilesTest extends TestCase {
    public function testAnUploadIsKeptForItsSenderAndFoundByReference(): void {
        $store = new InMemoryA2aFileRepository();
        $files = new A2aFiles($store);
        [$type, $body] = A2aFiles::multipart([['name' => 'фото.jpg', 'bytes' => "\xFF\xD8JPEG"], ['name' => 'note.txt', 'bytes' => 'текст']]);

        $kept = $files->upload(7, $type, $body);

        self::assertCount(2, $kept);
        self::assertSame('image/jpeg', $kept[0]['mediaType']);
        self::assertSame(6, $kept[0]['size']);
        $message = ['parts' => [['text' => 'смотри'], ['url' => 'a2a-file:' . $kept[0]['id']], ['url' => 'a2a-file:' . $kept[1]['id']]]];
        self::assertSame([], $files->ofMessage(8, $message), 'another agent does not get the files');
        $found = $files->ofMessage(7, $message);
        self::assertSame("\xFF\xD8JPEG", $found[0]['bytes']);
        self::assertSame('текст', $found[1]['bytes']);
        self::assertSame('text/plain', $found[1]['mediaType']);
    }

    public function testBytesInAPart(): void {
        $files = new A2aFiles(new InMemoryA2aFileRepository());

        $found = $files->ofMessage(7, ['parts' => [['raw' => base64_encode('OGG'), 'filename' => '../voice.ogg', 'mediaType' => 'audio/ogg']]]);

        self::assertSame('OGG', $found[0]['bytes']);
        self::assertSame('audio/ogg', $found[0]['mediaType']);
        self::assertStringNotContainsString('/', $found[0]['name']);
    }

    public function testAnUploadNeedsFilesAndABoundary(): void {
        $files = new A2aFiles(new InMemoryA2aFileRepository());
        $this->expectException(\DomainException::class);
        $files->upload(7, 'application/json', '{}');
    }

    public function testTooManyFiles(): void {
        $files = new A2aFiles(new InMemoryA2aFileRepository());
        [$type, $body] = A2aFiles::multipart(array_fill(0, 11, ['name' => 'a.txt', 'bytes' => 'a']));
        $this->expectException(\DomainException::class);
        $files->upload(7, $type, $body);
    }

    public function testTheFilesOfAnAnswer(): void {
        $store = new InMemoryA2aFileRepository();
        $files = new A2aFiles($store);

        $id = $files->answer(5, 'task-1', 'report.pdf', 'PDF');

        self::assertSame([['url' => 'a2a-file:' . $id, 'filename' => 'report.pdf', 'mediaType' => 'application/pdf']], $files->parts('task-1'));
        self::assertNull($files->download(6, $id));
        self::assertSame('PDF', $files->download(5, $id)['bytes'] ?? null);
    }
}
