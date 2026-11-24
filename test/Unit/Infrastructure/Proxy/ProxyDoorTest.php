<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Proxy;

use Amp\Cancellation;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use OpenCCK\Infrastructure\Proxy\ProxyDoor;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use function Amp\Socket\socketConnector;

/**
 * The door of the installation's way out: an HTTP and a SOCKS5 proxy in front of a route.
 */
final class ProxyDoorTest extends AsyncTestCase {
    private ProxyDoor $door;
    private ResourceServerSocket $site;
    /** @var list<string> what the site was sent */
    private array $seen = [];

    protected function setUp(): void {
        parent::setUp();
        // the route of the test is the loopback itself; port 9 stands for a site the route does not reach
        $route = new class (socketConnector()) implements SocketConnector {
            public function __construct(private readonly SocketConnector $direct) {}

            public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket {
                if (str_ends_with((string) $uri, ':9')) {
                    throw new ConnectException('refused');
                }
                return $this->direct->connect($uri, $context, $cancellation);
            }
        };
        $this->door = new ProxyDoor($route, new NullLogger());
        $site = listen('127.0.0.1:0');
        $this->site = $site;
        async(function () use ($site): void {
            while (($client = $site->accept()) !== null) {
                $chunk = (string) $client->read();
                $this->seen[] = $chunk;
                $client->write('echo:' . $chunk);
                $client->end();
            }
        })->ignore();
    }

    protected function tearDown(): void {
        $this->door->stop();
        $this->site->close();
        parent::tearDown();
    }

    private function sitePort(): int {
        $address = $this->site->getAddress()->toString();
        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    private static function readBytes(Socket $socket, int $length): string {
        $data = '';
        while (strlen($data) < $length && ($chunk = $socket->read(null, max(1, $length - strlen($data)))) !== null) {
            $data .= $chunk;
        }
        return $data;
    }

    public function testAConnectOpensATunnel(): void {
        $client = connect($this->door->http('127.0.0.1:0'));
        $target = '127.0.0.1:' . $this->sitePort();
        $client->write("CONNECT {$target} HTTP/1.1\r\nHost: {$target}\r\nProxy-Authorization: Basic YnJvd3Nlcjp4\r\n\r\n");

        self::assertSame("HTTP/1.1 200 Connection Established\r\n\r\n", self::readBytes($client, 39), 'credentials are not asked for and not needed');
        $client->write('hi');
        self::assertSame('echo:hi', buffer($client));
    }

    public function testAPlainRequestIsCarriedInOriginForm(): void {
        $client = connect($this->door->http('127.0.0.1:0'));
        $port = $this->sitePort();
        $client->write("GET http://127.0.0.1:{$port}/page?x=1 HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nProxy-Authorization: Basic c2VjcmV0\r\nProxy-Connection: keep-alive\r\nAccept: */*\r\n\r\n");

        self::assertStringStartsWith('echo:GET /page?x=1 HTTP/1.1', buffer($client));
        self::assertStringContainsString("Accept: */*\r\n", $this->seen[0]);
        self::assertStringContainsString("Connection: close\r\n", $this->seen[0]);
        self::assertStringNotContainsStringIgnoringCase('proxy-', $this->seen[0], 'what was said to the proxy stays with the proxy');
    }

    public function testAnUnreachableSiteIsABadGateway(): void {
        $client = connect($this->door->http('127.0.0.1:0'));
        $client->write("CONNECT 127.0.0.1:9 HTTP/1.1\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 502 ', buffer($client));
    }

    public function testNotAProxyRequestIsRefused(): void {
        $client = connect($this->door->http('127.0.0.1:0'));
        $client->write("GET /local HTTP/1.1\r\nHost: door\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 400 ', buffer($client));
    }

    public function testSocksCarriesAnAddressAndAName(): void {
        $address = $this->door->socks('127.0.0.1:0');
        $port = pack('n', $this->sitePort());
        foreach (["\x01\x7f\x00\x00\x01", "\x03\x09localhost"] as $target) {
            $client = connect($address);
            $client->write("\x05\x01\x00");
            self::assertSame("\x05\x00", self::readBytes($client, 2), 'no login');
            $client->write("\x05\x01\x00" . $target . $port);
            self::assertSame("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00", self::readBytes($client, 10));
            $client->write('hello');
            self::assertSame('echo:hello', buffer($client));
        }
    }

    public function testSocksRefusesWhatItDoesNotDo(): void {
        $address = $this->door->socks('127.0.0.1:0');

        // a client that wants a login only
        $client = connect($address);
        $client->write("\x05\x01\x02");
        self::assertSame("\x05\xff", buffer($client));

        // UDP ASSOCIATE: the door carries TCP only
        $client = connect($address);
        $client->write("\x05\x01\x00");
        self::readBytes($client, 2);
        $client->write("\x05\x03\x00\x01\x7f\x00\x00\x01\x00\x35");
        self::assertSame("\x05\x07", substr(buffer($client), 0, 2));

        // a site that is not there
        $client = connect($address);
        $client->write("\x05\x01\x00");
        self::readBytes($client, 2);
        $client->write("\x05\x01\x00\x01\x7f\x00\x00\x01\x00\x09");
        self::assertSame("\x05\x05", substr(buffer($client), 0, 2));
    }
}
