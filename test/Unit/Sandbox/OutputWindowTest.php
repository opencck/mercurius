<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use OpenCCK\Sandbox\OutputWindow;
use PHPUnit\Framework\TestCase;

final class OutputWindowTest extends TestCase {
    public function testWindowKeepsHeadAndTail(): void {
        $window = new OutputWindow(4, 4);
        $window->append('01234');
        $window->append('56789AB');

        self::assertSame("0123\n… пропущено 4 байт …\n89AB", $window->text());
        self::assertSame(12, $window->totalBytes());
    }

    public function testWindowUnderLimitIsWhole(): void {
        $window = new OutputWindow(4, 4);
        $window->append('abc');
        $window->append('defgh');

        self::assertSame('abcdefgh', $window->text());
    }

    public function testWindowMemoryIsBounded(): void {
        $window = new OutputWindow(50, 50);
        for ($i = 0; $i < 100_000; $i++) {
            $window->append('x');
        }

        self::assertLessThan(200, strlen($window->text()));
        self::assertSame(100_000, $window->totalBytes());
    }
}
