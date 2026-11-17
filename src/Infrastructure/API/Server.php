<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use OpenCCK\App\Service\HealthService;
use OpenCCK\Infrastructure\API\Handler\HealthHandler;
use OpenCCK\Infrastructure\API\Middleware\AccessLogMiddleware;
use OpenCCK\Infrastructure\API\Middleware\RequestIdMiddleware;
use Psr\Log\LoggerInterface;

use function Amp\Http\Server\Middleware\stackMiddleware;

/**
 * HTTP server behind Nginx Proxy Manager: trusts X-Forwarded-For only from private networks (Docker).
 */
final class Server implements AppModuleInterface {
    private const TRUSTED_PROXIES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1/32'];

    private ?SocketHttpServer $httpServer = null;

    /**
     * @param int<1, 65535> $port
     * @param list<array{non-empty-string, non-empty-string, RequestHandler|\Closure(\Amp\Http\Server\HttpServer): RequestHandler}> $routes extra
     *        routes: method, path, handler (or a function that makes it with the server, as a WebSocket endpoint needs)
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly HealthService $health,
        private readonly string $version,
        private readonly array $routes = [],
    ) {}

    public function start(): void {
        $server = SocketHttpServer::createForBehindProxy($this->logger, ForwardedHeaderType::XForwardedFor, self::TRUSTED_PROXIES);
        $errorHandler = new DefaultErrorHandler();
        $router = new Router($server, $this->logger, $errorHandler);

        $router->addRoute('GET', '/health', new HealthHandler($this->health, $this->version));
        $router->addRoute('GET', '/', new ClosureRequestHandler(fn(): Response => new Response(
            HttpStatus::OK,
            ['content-type' => 'application/json; charset=utf-8'],
            json_encode(['name' => 'mercurius', 'version' => $this->version], JSON_THROW_ON_ERROR),
        )));
        foreach ($this->routes as [$method, $path, $routeHandler]) {
            $router->addRoute($method, $path, $routeHandler instanceof RequestHandler ? $routeHandler : $routeHandler($server));
        }

        $handler = stackMiddleware($router, new RequestIdMiddleware(), new AccessLogMiddleware($this->logger));

        $server->expose(new InternetAddress($this->host, $this->port));
        $server->start($handler, $errorHandler);
        $this->httpServer = $server;

        $this->logger->info('HTTP server listening', ['host' => $this->host, 'port' => $this->port]);
    }

    public function stop(): void {
        $this->httpServer?->stop();
        $this->httpServer = null;
    }
}
