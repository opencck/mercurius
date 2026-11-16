<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Http;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Socket\DnsSocketConnector;
use Mercurius\Connectors\RouteFactory;
use OpenCCK\App\Agent\Tool\AddressGuard;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * HTTP clients of the application. A route URI (VLESS, proxy) makes all connections of the client go through it;
 * connections are pooled, so a VLESS tunnel is not opened per request.
 */
final class HttpClients {
    public static function create(#[SensitiveParameter] ?string $routeUri = null, ?LoggerInterface $logger = null): HttpClient {
        $connector = RouteFactory::fromUri($routeUri, $logger);

        return (new HttpClientBuilder())
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))
            ->retry(0)
            ->build();
    }

    /**
     * A client for agent tools: direct route, every dialed address checked by the guard, redirects handled by the tool.
     */
    public static function guarded(AddressGuard $guard): HttpClient {
        return (new HttpClientBuilder())
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(new GuardedConnector(new DnsSocketConnector(), $guard))))
            ->followRedirects(0)
            ->retry(0)
            ->build();
    }
}
