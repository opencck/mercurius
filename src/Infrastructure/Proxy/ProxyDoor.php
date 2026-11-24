<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Proxy;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;
use Closure;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/**
 * A door to the installation's way out for what cannot take a route by itself: an HTTP proxy and a SOCKS5 proxy that
 * open every connection through one route (the owner's proxy). A command in the sandbox uses the door by its address
 * and never sees the address and the password of the proxy behind it. The door asks for no login: it listens only
 * in the networks of the installation. It carries TCP only.
 *
 * With VLESS the same door is the `vless-proxy` of rekryt/amphp-vless; this one serves a route that is not VLESS,
 * which those servers do not take.
 *
 * When the route is an HTTP proxy, its address (`$httpProxy`, host:port) and its `Proxy-Authorization` are given too:
 * a plain `http://` request is then handed to that proxy as it is, because such proxies commonly tunnel only to 443.
 *
 * spec: brief/agent-loop.md#прокси-для-команд-песочницы
 * ref: references/amphp-vless/src/Server/HttpProxyServer.php@065417f:L185-L290 (tunnel, forward) — 200 only after the
 *      route opened the connection, 502 otherwise; a plain request is rebuilt in origin form without the hop-by-hop
 *      headers and with `Connection: close`
 * ref: references/amphp-vless/src/Server/Socks5ProxyServer.php@065417f (serve) — the greeting, the request and the
 *      reply codes; no login and no UDP here
 * ref: references/amphp-vless/src/Server/ProxyServer.php@065417f:L102-L160 (listen) — a ceiling of connections and a
 *      time limit for the negotiation
 */
final class ProxyDoor {
    private const MAX_CONNECTIONS = 256;
    private const HEAD_BYTES = 16384;
    private const NEGOTIATION_SECONDS = 15.0;
    private const CONNECT_SECONDS = 20.0;
    /** `max-forwards` goes too: with it a proxy on the way answers instead of the site */
    private const HOP_BY_HOP = ['connection', 'keep-alive', 'max-forwards', 'proxy-authenticate', 'proxy-authorization', 'proxy-connection', 'te', 'trailer', 'upgrade'];
    /** the methods of a plain request the door carries */
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var list<ResourceServerSocket> */
    private array $servers = [];
    private int $active = 0;

    public function __construct(
        private readonly SocketConnector $route,
        private readonly LoggerInterface $logger,
        private readonly ?string $httpProxy = null,
        #[SensitiveParameter]
        private readonly ?string $proxyAuthorization = null,
    ) {}

    /** Starts the HTTP door (CONNECT and plain requests in absolute form) and gives the address it listens on. */
    public function http(string $address): SocketAddress {
        return $this->open($address, $this->serveHttp(...));
    }

    /** Starts the SOCKS5 door (CONNECT of TCP, no login) and gives the address it listens on. */
    public function socks(string $address): SocketAddress {
        return $this->open($address, $this->serveSocks(...));
    }

    public function stop(): void {
        foreach ($this->servers as $server) {
            $server->close();
        }
        $this->servers = [];
    }

    /** @param Closure(Socket, Cancellation): void $serve */
    private function open(string $address, Closure $serve): SocketAddress {
        $server = listen($address);
        $this->servers[] = $server;
        async(function () use ($server, $serve): void {
            while (($client = $server->accept()) !== null) {
                if ($this->active >= self::MAX_CONNECTIONS) {
                    $client->close();
                    continue;
                }
                ++$this->active;
                async(function () use ($client, $serve): void {
                    try {
                        $serve($client, new TimeoutCancellation(self::NEGOTIATION_SECONDS));
                    } catch (Throwable) {
                        // a client that went away or kept silent: its connection is closed below
                    } finally {
                        --$this->active;
                        $client->close();
                    }
                })->ignore();
            }
        })->ignore();
        return $server->getAddress();
    }

    private function serveHttp(Socket $client, Cancellation $wait): void {
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = $client->read($wait);
            if ($chunk === null || strlen($head) + strlen($chunk) > self::HEAD_BYTES) {
                return;
            }
            $head .= $chunk;
        }
        $end = (int) strpos($head, "\r\n\r\n");
        $rest = substr($head, $end + 4);
        $lines = explode("\r\n", substr($head, 0, $end));
        $first = array_shift($lines);

        if (preg_match('~^CONNECT (\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._-]+):(\d{1,5}) HTTP/1\.[01]$~', $first, $match) === 1) {
            $remote = $this->dial(trim($match[1], '[]'), (int) $match[2]);
            if ($remote === null) {
                $client->write("HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
                return;
            }
            $client->write("HTTP/1.1 200 Connection Established\r\n\r\n");
            self::relay($client, $remote, $rest);
            return;
        }

        $uri = preg_match('~^([A-Z]{1,16}) (http://\S+) HTTP/1\.[01]$~', $first, $match) === 1 ? parse_url($match[2]) : false;
        // TRACE is answered by a proxy itself with the request it got: that would give back the login of the owner's proxy
        if (!is_array($uri) || !isset($uri['host']) || !in_array($match[1], self::METHODS, true)) {
            $client->write("HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\nThis is a proxy: CONNECT host:port, or a request in absolute form.\n");
            return;
        }
        if ($this->httpProxy !== null) {
            // the owner's HTTP proxy takes the request in absolute form itself, with its own login
            $remote = $this->proxy();
            $request = sprintf("%s %s HTTP/1.1\r\n", $match[1], $match[2]) . ($this->proxyAuthorization === null ? '' : 'Proxy-Authorization: ' . $this->proxyAuthorization . "\r\n");
        } else {
            $remote = $this->dial(trim($uri['host'], '[]'), $uri['port'] ?? 80);
            $request = sprintf("%s %s HTTP/1.1\r\n", $match[1], ($uri['path'] ?? '/') . (isset($uri['query']) ? '?' . $uri['query'] : ''));
        }
        if ($remote === null) {
            $client->write("HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
            return;
        }
        foreach ($lines as $line) {
            $name = strtolower(trim((string) strstr($line, ':', true)));
            if ($name !== '' && !in_array($name, self::HOP_BY_HOP, true)) {
                $request .= $line . "\r\n";
            }
        }
        // one exchange per connection: the door does not follow where a response ends
        self::relay($client, $remote, $request . "Connection: close\r\n\r\n" . $rest);
    }

    private function serveSocks(Socket $client, Cancellation $wait): void {
        $buffer = '';
        $take = static function (int $length) use ($client, $wait, &$buffer): ?string {
            while (strlen($buffer) < $length) {
                $chunk = $client->read($wait);
                if ($chunk === null) {
                    return null;
                }
                $buffer .= $chunk;
            }
            $bytes = substr($buffer, 0, $length);
            $buffer = substr($buffer, $length);
            return $bytes;
        };

        $greeting = $take(2);
        if ($greeting === null || $greeting[0] !== "\x05") {
            return;
        }
        $methods = $take(ord($greeting[1]));
        if ($methods === null) {
            return;
        }
        if (!str_contains($methods, "\x00")) {
            $client->write("\x05\xff");
            return;
        }
        $client->write("\x05\x00");

        $request = $take(4);
        if ($request === null || $request[0] !== "\x05") {
            return;
        }
        $host = match ($request[3]) {
            "\x01" => ($raw = $take(4)) === null ? null : inet_ntop($raw),
            "\x04" => ($raw = $take(16)) === null ? null : inet_ntop($raw),
            "\x03" => ($length = $take(1)) === null ? null : $take(ord($length)),
            default => false,
        };
        $port = is_string($host) ? $take(2) : null;
        if ($host === false || ($request[1] !== "\x01" && is_string($port))) {
            // 8 — an address type, 7 — a command (BIND, UDP ASSOCIATE) the door does not serve
            $client->write($host === false ? "\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00" : "\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00");
            return;
        }
        if (!is_string($host) || $port === null || preg_match('~^[A-Za-z0-9._:-]{1,255}$~', $host) !== 1) {
            return;
        }
        $remote = $this->dial($host, (ord($port[0]) << 8) | ord($port[1]));
        if ($remote === null) {
            $client->write("\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");
            return;
        }
        $client->write("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");
        self::relay($client, $remote, $buffer);
    }

    /** Opens a connection through the route; null — the route did not reach the site. */
    private function dial(string $host, int $port): ?Socket {
        try {
            return $this->route->connect(
                sprintf(str_contains($host, ':') ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $host, $port),
                (new ConnectContext())->withConnectTimeout(self::CONNECT_SECONDS),
                new TimeoutCancellation(self::CONNECT_SECONDS),
            );
        } catch (Throwable $e) {
            // the name of a site is no secret; the message of a route may carry its address, so only the class
            $this->logger->warning('proxy door: the route did not open a connection', ['host' => $host, 'port' => $port, 'error' => $e::class]);
            return null;
        }
    }

    /** Opens a connection to the owner's HTTP proxy itself; null — it is not reachable. */
    private function proxy(): ?Socket {
        try {
            return connect('tcp://' . (string) $this->httpProxy, (new ConnectContext())->withConnectTimeout(self::CONNECT_SECONDS), new TimeoutCancellation(self::CONNECT_SECONDS));
        } catch (Throwable $e) {
            $this->logger->warning('proxy door: the proxy is not reachable', ['error' => $e::class]);
            return null;
        }
    }

    /** Carries bytes both ways until either side ends. */
    private static function relay(Socket $client, Socket $remote, string $first): void {
        try {
            if ($first !== '') {
                $remote->write($first);
            }
            $up = async(self::carry(...), $client, $remote);
            self::carry($remote, $client);
            $up->await();
        } catch (Throwable) {
            // a closed side ends the exchange
        } finally {
            $remote->close();
            $client->close();
        }
    }

    private static function carry(Socket $from, Socket $to): void {
        try {
            pipe($from, $to);
        } catch (Throwable) {
            // a closed side ends the exchange
        } finally {
            $to->close();
            $from->close();
        }
    }
}
