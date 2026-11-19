<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Http;

use Amp\Cancellation;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\Infrastructure\Http\GuardedConnector;

final class GuardedConnectorTest extends AsyncTestCase {
    /** @var list<string> */
    public array $dialed = [];

    private function connector(): GuardedConnector {
        $inner = new class($this) implements SocketConnector {
            public function __construct(private readonly GuardedConnectorTest $test) {}

            public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket {
                $this->test->dialed[] = (string) $uri;
                throw new ConnectException('stop here');
            }
        };
        $guard = new AddressGuard(static fn(string $host): array => match ($host) {
            'example.com' => ['93.184.216.34'],
            'v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946'],
            'rebind.example.com' => ['10.0.0.1'],
            default => [],
        });
        return new GuardedConnector($inner, $guard);
    }

    public function testDialsTheCheckedAddress(): void {
        try {
            $this->connector()->connect('tcp://example.com:443');
        } catch (ConnectException) {
        }
        self::assertSame(['tcp://93.184.216.34:443'], $this->dialed);
    }

    public function testIpv6IsBracketed(): void {
        try {
            $this->connector()->connect('tcp://v6.example.com:80');
        } catch (ConnectException) {
        }
        self::assertSame(['tcp://[2606:2800:220:1:248:1893:25c8:1946]:80'], $this->dialed);
    }

    public function testInternalAddressIsNeverDialed(): void {
        try {
            $this->connector()->connect('tcp://rebind.example.com:80');
            self::fail('No exception');
        } catch (ConnectException $e) {
            self::assertStringContainsString('внутренний', $e->getMessage());
        }
        self::assertSame([], $this->dialed);
    }
}
