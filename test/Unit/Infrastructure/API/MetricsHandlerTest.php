<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\API;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri\Http;
use OpenCCK\App\Service\MetricsText;
use OpenCCK\Domain\Repository\MetricsRepository;
use OpenCCK\Infrastructure\API\Handler\MetricsHandler;

final class MetricsHandlerTest extends AsyncTestCase {
    /** @param \ArrayObject<int, true>|null $reads one entry per read of the database */
    private static function figures(?\ArrayObject $reads = null): MetricsRepository {
        return new class ($reads ?? new \ArrayObject()) implements MetricsRepository {
            /** @param \ArrayObject<int, true> $reads */
            public function __construct(private readonly \ArrayObject $reads) {}

            public function workersPerRun(array $bounds): array {
                $this->reads->append(true);
                return ['buckets' => [4, 5, 6, 6, 7, 7], 'sum' => 9, 'count' => 7];
            }

            public function runs(): array {
                return [['status' => 'done', 'reason' => 'answered', 'count' => 7], ['status' => 'running', 'reason' => '', 'count' => 1]];
            }

            public function models(): array {
                return [['model' => 'openrouter:z-ai/glm-5.3-flash', 'input' => 1000, 'output' => 200, 'cache_read' => 300, 'cache_write' => 0, 'reasoning' => 50, 'cost' => 0.0123]];
            }

            public function tools(): array {
                return [['tool' => 'run_command', 'status' => 'error', 'count' => 2]];
            }

            public function approvals(): array {
                return [['outcome' => 'denied', 'count' => 1]];
            }

            public function workers(): array {
                return [['kind' => 'verifier', 'status' => 'done', 'count' => 2]];
            }

            public function durations(array $bounds): array {
                return [['kind' => 'model', 'buckets' => array_fill(0, count($bounds), 3), 'sum' => 4.5, 'count' => 3]];
            }
        };
    }

    private function request(?string $authorization): Request {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://localhost/metrics'));
        if ($authorization !== null) {
            $request->setHeader('authorization', $authorization);
        }
        return $request;
    }

    public function testMetricsNeedTheToken(): void {
        $handler = new MetricsHandler(new MetricsText(self::figures()), 's3cret-token');

        self::assertSame(401, $handler->handleRequest($this->request(null))->getStatus());
        self::assertSame(401, $handler->handleRequest($this->request('Bearer wrong'))->getStatus());
        self::assertSame(200, $handler->handleRequest($this->request('Bearer s3cret-token'))->getStatus());
        self::assertSame(404, (new MetricsHandler(new MetricsText(self::figures()), null))->handleRequest($this->request('Bearer s3cret-token'))->getStatus());
    }

    public function testMetricsText(): void {
        $text = (new MetricsText(self::figures()))->render();

        self::assertStringContainsString("# TYPE mercurius_runs_total counter\nmercurius_runs_total{status=\"done\",reason=\"answered\"} 7", $text);
        self::assertStringContainsString('mercurius_runs_active{status="running"} 1', $text);
        self::assertStringNotContainsString('mercurius_runs_total{status="running"', $text);
        self::assertStringContainsString('mercurius_model_tokens_total{model="openrouter:z-ai/glm-5.3-flash",bucket="cache_read"} 300', $text);
        self::assertStringContainsString('mercurius_model_cost_usd_total{model="openrouter:z-ai/glm-5.3-flash"} 0.0123', $text);
        self::assertStringContainsString('mercurius_tool_calls_total{tool="run_command",status="error"} 2', $text);
        self::assertStringContainsString('mercurius_workers_total{kind="verifier",status="done"} 2', $text);
        self::assertStringContainsString('mercurius_approvals_total{outcome="denied"} 1', $text);
        self::assertStringContainsString('mercurius_step_duration_seconds_bucket{kind="model",le="+Inf"} 3', $text);
        self::assertStringContainsString('mercurius_step_duration_seconds_sum{kind="model"} 4.5', $text);
        self::assertStringEndsWith("\n", $text);
    }

    public function testWorkersPerRunIsAHistogram(): void {
        // spec: brief/operations.md#трассировка-и-метрики — how many workers a run starts
        $text = (new MetricsText(self::figures()))->render();

        self::assertStringContainsString("# TYPE mercurius_workers_per_run histogram\n", $text);
        self::assertStringContainsString('mercurius_workers_per_run_bucket{le="0"} 4', $text);
        self::assertStringContainsString('mercurius_workers_per_run_bucket{le="+Inf"} 7', $text);
        self::assertStringContainsString('mercurius_workers_per_run_sum 9', $text);
        self::assertStringContainsString('mercurius_workers_per_run_count 7', $text);
    }

    public function testTheAnswerIsKeptForThirtySeconds(): void {
        // spec: brief/operations.md#трассировка-и-метрики — every scrape does not hit the database
        $reads = new \ArrayObject();
        $figures = self::figures($reads);
        $now = 1000.0;
        $handler = new MetricsHandler(new MetricsText($figures), 's3cret-token', static function () use (&$now): float {
            return $now;
        });

        $handler->handleRequest($this->request('Bearer s3cret-token'));
        $now += 29.0;
        $handler->handleRequest($this->request('Bearer s3cret-token'));
        self::assertCount(1, $reads, 'a scrape within 30 s gets the kept answer');
        $now += 2.0;
        $handler->handleRequest($this->request('Bearer s3cret-token'));
        self::assertCount(2, $reads);
    }

    public function testLabelValuesAreEscaped(): void {
        self::assertSame('a\\"b\\\\c\\n', MetricsText::label("a\"b\\c\n"));
    }
}
