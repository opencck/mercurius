<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpenCCK\App\Service\HealthService;

/**
 * GET /health — 200 when every dependency is usable, 503 otherwise.
 */
final class HealthHandler implements RequestHandler {
    public function __construct(
        private readonly HealthService $health,
        private readonly string $version,
    ) {}

    public function handleRequest(Request $request): Response {
        $report = $this->health->run();

        return new Response(
            $report->healthy ? HttpStatus::OK : HttpStatus::SERVICE_UNAVAILABLE,
            ['content-type' => 'application/json; charset=utf-8', 'cache-control' => 'no-store'],
            json_encode([
                'status' => $report->healthy ? 'ok' : 'fail',
                'version' => $this->version,
                'checks' => $report->checks,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
