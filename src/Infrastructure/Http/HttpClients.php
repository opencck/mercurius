<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Http;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Mercurius\Connectors\RouteFactory;
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
}
