<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Gives every request an id: keeps a safe incoming X-Request-Id or generates one,
 * stores it as a request attribute and returns it in the response header.
 */
final class RequestIdMiddleware implements Middleware {
    public const ATTRIBUTE = 'request_id';
    private const HEADER = 'x-request-id';
    private const SAFE = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Request id assigned by this middleware, or null outside of it. */
    public static function of(Request $request): ?string {
        if (!$request->hasAttribute(self::ATTRIBUTE)) {
            return null;
        }
        /** @var mixed $value */
        $value = $request->getAttribute(self::ATTRIBUTE);
        return is_string($value) ? $value : null;
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response {
        $incoming = $request->getHeader(self::HEADER);
        $id = $incoming !== null && preg_match(self::SAFE, $incoming) ? $incoming : bin2hex(random_bytes(16));

        $request->setAttribute(self::ATTRIBUTE, $id);
        $response = $requestHandler->handleRequest($request);
        $response->setHeader(self::HEADER, $id);

        return $response;
    }
}
