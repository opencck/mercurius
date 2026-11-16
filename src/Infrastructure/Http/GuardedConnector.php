<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Http;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use OpenCCK\App\Agent\Tool\AddressGuard;
use OpenCCK\App\Agent\Tool\AddressRefused;

/**
 * Resolves the host itself and dials only an address AddressGuard has checked, so a name that changes its answer
 * between the check and the connection (DNS rebinding) still cannot reach an internal address. TLS keeps the host
 * name as the peer name, which the HTTP client sets.
 *
 * spec: BRIEF.md#подтверждения-и-запреты — internal addresses are never reachable
 */
final class GuardedConnector implements SocketConnector {
    public function __construct(
        private readonly SocketConnector $inner,
        private readonly AddressGuard $guard,
    ) {}

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket {
        $uri = (string) $uri;
        $authority = preg_replace('#^[a-z]+://#i', '', $uri) ?? $uri;
        if (preg_match('#^\[?([^\]]+?)\]?:(\d+)$#', $authority, $match) !== 1) {
            throw new ConnectException(sprintf('Unsupported address %s', $uri));
        }
        try {
            $ips = $this->guard->resolve($match[1]);
        } catch (AddressRefused $e) {
            throw new ConnectException($e->getMessage(), 0, $e);
        }
        $ip = $ips[0];
        $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        return $this->inner->connect('tcp://' . $host . ':' . $match[2], $context, $cancellation);
    }
}
