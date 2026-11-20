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
 * spec: brief/operations.md#трассировка-и-метрики — /metrics behind METRICS_TOKEN
 */
final class MetricsHandler implements RequestHandler {
    /** seconds a rendered answer is kept: every scrape does not read the database */
    private const KEEP = 30.0;

    private ?string $kept = null;
    private float $keptAt = 0.0;

    /** @param (\Closure(): float)|null $now seconds, for tests; null — the clock */
    public function __construct(
        private readonly MetricsText $metrics,
        #[SensitiveParameter] private readonly ?string $token,
        private readonly ?\Closure $now = null,
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
            $this->text(),
        );
    }

    /** spec: brief/operations.md#трассировка-и-метрики — the answer is kept for 30 s */
    private function text(): string {
        $now = $this->now === null ? microtime(true) : ($this->now)();
        if ($this->kept === null || $now - $this->keptAt >= self::KEEP) {
            $this->kept = $this->metrics->render();
            $this->keptAt = $now;
        }
        return $this->kept;
    }
}
