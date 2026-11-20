<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Ssh;

use OpenCCK\Infrastructure\Ssh\ConnectionPool;
use OpenCCK\Infrastructure\Ssh\PooledConnection;
use PHPUnit\Framework\TestCase;

/**
 * One SSH connection per server for a series of commands, closed after it idles.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a pool of SSH connections
 */
final class ConnectionPoolTest extends TestCase {
    private int $now = 1_000;
    private int $opened = 0;
    /** @var list<int> */
    private array $closed = [];
    /** @var array<int, bool> */
    private array $dead = [];

    private function pool(int $max = 16, int $channels = 8): ConnectionPool {
        return new ConnectionPool(90, $max, $channels, fn(): int => $this->now);
    }

    /** @return \Closure(): PooledConnection */
    private function open(): \Closure {
        return function (): PooledConnection {
            $id = ++$this->opened;
            return new PooledConnection(
                $id,
                fn(): bool => !($this->dead[$id] ?? false),
                function () use ($id): void {
                    $this->closed[] = $id;
                },
            );
        };
    }

    public function testFiveCommandsInARowGoThroughOneConnection(): void {
        $pool = $this->pool();
        for ($i = 0; $i < 5; $i++) {
            $connection = $pool->acquire('web', $this->open());
            $this->now += 10;
            $pool->release($connection);
        }

        self::assertSame(1, $this->opened);
        self::assertSame(5, $connection->commands);
    }

    public function testAnIdleConnectionIsClosedAndADeadOneReplaced(): void {
        $pool = $this->pool();
        $pool->release($pool->acquire('web', $this->open()));
        $this->now += 60;
        self::assertSame(0, $pool->sweep(), 'not idle long enough');
        $this->now += 31;
        self::assertSame(1, $pool->sweep());
        self::assertSame([1], $this->closed);

        $pool->release($pool->acquire('web', $this->open()));
        $this->dead[2] = true;
        self::assertSame(3, $pool->acquire('web', $this->open())->id, 'a dead connection is not given out');
        self::assertContains(2, $this->closed);
    }

    public function testABrokenConnectionIsDroppedAndOthersAreKeptApart(): void {
        $pool = $this->pool();
        $web = $pool->acquire('web', $this->open());
        $db = $pool->acquire('db', $this->open());
        self::assertNotSame($web->id, $db->id);

        $pool->release($web, broken: true);
        self::assertSame([$web->id], $this->closed);
        self::assertNotSame($web->id, $pool->acquire('web', $this->open())->id);
        $pool->release($db);
    }

    public function testABusyConnectionSharesChannelsUpToTheLimitThenOpensAnother(): void {
        $pool = $this->pool(channels: 2);
        $a = $pool->acquire('web', $this->open());
        $b = $pool->acquire('web', $this->open());
        $c = $pool->acquire('web', $this->open());

        self::assertSame($a->id, $b->id, 'channels of one connection');
        self::assertNotSame($a->id, $c->id);
        $pool->release($c);
        self::assertContains($c->id, $this->closed, 'an extra connection is not kept');
    }

    public function testThePoolKeepsAtMostItsLimitClosingTheLeastRecentlyUsed(): void {
        $pool = $this->pool(max: 2);
        foreach (['a', 'b', 'c'] as $key) {
            $pool->release($pool->acquire($key, $this->open()));
            $this->now += 1;
        }

        self::assertSame([1], $this->closedIds());
        $pool->closeAll();
        self::assertSame([1, 2, 3], $this->closedIds());
    }

    public function testClosingAConnectionWhoseSocketIsGoneDoesNotThrow(): void {
        // a connection through a server in between whose socket closed first: closing writes to a closed stream
        $connection = new PooledConnection(1, static fn(): bool => true, static function (): void {
            throw new \Amp\ByteStream\ClosedException('The stream is not writable');
        });

        $connection->close();

        self::assertFalse($connection->alive());
    }

    /** @return list<int> */
    private function closedIds(): array {
        return $this->closed;
    }
}
