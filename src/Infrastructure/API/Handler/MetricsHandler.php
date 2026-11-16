<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpenCCK\App\Service\MetricsText;
use SensitiveParameter;

/**
 * GET /metrics for Prometheus. The domain is public and every path goes through the proxy, so the endpoint needs a
 * bearer token; without a configured token it does not exist.
 *
 * spec: BRIEF.md#трассировка-и-метрики — /metrics behind METRICS_TOKEN
 */
final class MetricsHandler implements RequestHandler {
    public function __construct(
        private readonly MetricsText $metrics,
        #[SensitiveParameter] private readonly ?string $token,
    ) {}

    public function handleRequest(Request $request): Response {
        if ($this->token === null || $this->token === '') {
            return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'text/plain; charset=utf-8'], "Not found\n");
        }
        $given = $request->getHeader('authorization') ?? '';
        if (!hash_equals('Bearer ' . $this->token, $given)) {
            return new Response(HttpStatus::UNAUTHORIZED, ['content-type' => 'text/plain; charset=utf-8', 'www-authenticate' => 'Bearer'], "Unauthorized\n");
        }
        return new Response(
            HttpStatus::OK,
            ['content-type' => 'text/plain; version=0.0.4; charset=utf-8', 'cache-control' => 'no-store'],
            $this->metrics->render(),
        );
    }
}
