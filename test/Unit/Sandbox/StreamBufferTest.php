<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Sandbox\StreamBuffer;

use function Amp\async;
use function Amp\delay;

final class StreamBufferTest extends AsyncTestCase {
    public function testATakeWaitsForDataAndGetsItAtOnce(): void {
        $buffer = new StreamBuffer(1024);
        $taken = async(static fn(): string => $buffer->take(100, 5.0));
        delay(0.05);

        $started = microtime(true);
        $buffer->append("line\n");

        self::assertSame("line\n", $taken->await());
        self::assertLessThan(1.0, microtime(true) - $started, 'the waiting client is woken, not left to its timeout');
    }

    public function testNothingInTimeIsAnEmptyAnswer(): void {
        $buffer = new StreamBuffer(1024);

        self::assertSame('', $buffer->take(100, 0.05));
        self::assertFalse($buffer->ended());
    }

    public function testAFullBufferHoldsTheReaderUntilTheClientTakes(): void {
        $buffer = new StreamBuffer(4);
        $buffer->append('abcd');
        $second = async(static fn() => $buffer->append('efgh'));
        delay(0.05);
        self::assertFalse($second->isComplete(), 'full: the pipe is not read, the process waits on its write');
        self::assertSame(4, $buffer->pending());

        self::assertSame('ab', $buffer->take(2, 0.0));
        delay(0.01);

        self::assertTrue($second->isComplete());
        self::assertSame('cdefgh', $buffer->take(100, 0.0), 'nothing is dropped');
    }

    public function testTheEndWakesBothSidesAndKeepsWhatIsLeft(): void {
        $buffer = new StreamBuffer(2);
        $buffer->append('xy');
        $blocked = async(static fn() => $buffer->append('z'));
        $waiting = new StreamBuffer(8);
        $client = async(static fn(): string => $waiting->take(10, 5.0));
        delay(0.05);

        $buffer->close();
        $waiting->close();

        $blocked->await();
        self::assertSame('', $client->await(), 'a closed stream answers at once');
        self::assertFalse($buffer->ended(), 'what was buffered can still be taken');
        self::assertSame('xy', $buffer->take(10, 0.0));
        self::assertTrue($buffer->ended());
    }
}
