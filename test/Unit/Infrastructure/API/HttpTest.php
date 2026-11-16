<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\API;

use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri\Http;
use OpenCCK\App\Service\HealthCheck;
use OpenCCK\App\Service\HealthService;
use OpenCCK\Infrastructure\API\Handler\HealthHandler;
use OpenCCK\Infrastructure\API\Middleware\RequestIdMiddleware;

final class HttpTest extends AsyncTestCase {
    /** @param array<non-empty-string, string> $headers */
    private function request(string $path, array $headers = []): Request {
        return new Request($this->createMock(Client::class), 'GET', Http::new('http://localhost:8080' . $path), $headers);
    }

    /** @return array<mixed> */
    private function json(Response $response): array {
        $data = json_decode(ByteStream\buffer($response->getBody()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param non-empty-string $name */
    private function check(string $name, bool $ok, float $delay = 0.0): HealthCheck {
        return new class ($name, $ok, $delay) implements HealthCheck {
            /** @param non-empty-string $name */
            public function __construct(private string $name, private bool $ok, private float $delay) {}
            public function name(): string { return $this->name; }
            public function check(): void {
                if ($this->delay > 0) {
                    \Amp\delay($this->delay);
                }
                if (!$this->ok) {
                    throw new \RuntimeException('connection refused');
                }
            }
        };
    }

    public function testHealthyReturns200(): void {
        $handler = new HealthHandler(new HealthService([$this->check('database', true)], 1.0), '1.2.3');
        $response = $handler->handleRequest($this->request('/health'));

        self::assertSame(HttpStatus::OK, $response->getStatus());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('content-type'));
        $body = $this->json($response);
        self::assertSame('ok', $body['status']);
        self::assertSame('1.2.3', $body['version']);
        self::assertSame(['database' => 'ok'], $body['checks']);
    }

    public function testFailedCheckReturns503WithoutDetails(): void {
        $handler = new HealthHandler(new HealthService([$this->check('database', false)], 1.0), '1.2.3');
        $response = $handler->handleRequest($this->request('/health'));

        self::assertSame(HttpStatus::SERVICE_UNAVAILABLE, $response->getStatus());
        $body = $this->json($response);
        self::assertSame('fail', $body['status']);
        self::assertSame(['database' => 'fail'], $body['checks']);
        self::assertStringNotContainsString('refused', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testSlowCheckTimesOut(): void {
        $service = new HealthService([$this->check('database', true, 0.5)], 0.05);

        self::assertSame(['database' => 'timeout'], $service->run()->checks);
        self::assertFalse($service->run()->healthy);
    }

    public function testRequestIdIsGeneratedAndReturned(): void {
        $seen = null;
        $inner = new ClosureRequestHandler(function (Request $request) use (&$seen): Response {
            $seen = RequestIdMiddleware::of($request);
            return new Response(HttpStatus::OK);
        });

        $response = (new RequestIdMiddleware())->handleRequest($this->request('/'), $inner);

        self::assertIsString($seen);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $seen);
        self::assertSame($seen, $response->getHeader('x-request-id'));
    }

    public function testValidIncomingRequestIdIsKept(): void {
        $inner = new ClosureRequestHandler(static fn(): Response => new Response(HttpStatus::OK));
        $response = (new RequestIdMiddleware())->handleRequest($this->request('/', ['x-request-id' => 'abc-123_DEF']), $inner);

        self::assertSame('abc-123_DEF', $response->getHeader('x-request-id'));
    }

    public function testUnsafeIncomingRequestIdIsReplaced(): void {
        $inner = new ClosureRequestHandler(static fn(): Response => new Response(HttpStatus::OK));
        $response = (new RequestIdMiddleware())->handleRequest($this->request('/', ['x-request-id' => "bad\nvalue"]), $inner);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $response->getHeader('x-request-id'));
    }
}
