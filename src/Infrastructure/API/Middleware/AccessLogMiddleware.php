<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One log line per request with its id, status and duration. Must run after RequestIdMiddleware.
 * The query string is not logged: it may carry tokens.
 */
final class AccessLogMiddleware implements Middleware {
    public function __construct(private readonly LoggerInterface $logger) {}

    /** Real client behind a trusted proxy (X-Forwarded-For), otherwise the socket peer. */
    private static function clientAddress(Request $request): string {
        if ($request->hasAttribute(Forwarded::class)) {
            /** @var mixed $forwarded */
            $forwarded = $request->getAttribute(Forwarded::class);
            if ($forwarded instanceof Forwarded) {
                return $forwarded->getFor()->toString();
            }
        }
        return $request->getClient()->getRemoteAddress()->toString();
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response {
        $start = microtime(true);
        $context = [
            'request_id' => RequestIdMiddleware::of($request),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'client' => self::clientAddress($request),
        ];

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (Throwable $e) {
            $this->logger->error('HTTP request failed', $context + [
                'duration_ms' => round((microtime(true) - $start) * 1000.0, 2),
                'error' => $e::class,
            ]);
            throw $e;
        }

        $this->logger->info('HTTP request', $context + [
            'status' => $response->getStatus(),
            'duration_ms' => round((microtime(true) - $start) * 1000.0, 2),
        ]);

        return $response;
    }
}
