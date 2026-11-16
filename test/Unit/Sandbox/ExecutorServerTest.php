<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use Amp\Cancellation;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri\Http;
use LogicException;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Sandbox\CommandResult;
use OpenCCK\Sandbox\ExecutorServer;
use OpenCCK\Sandbox\FileStore;
use OpenCCK\Sandbox\Runner;
use OpenCCK\Sandbox\SandboxError;
use OpenCCK\Sandbox\Workspace;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

final class ExecutorServerTest extends AsyncTestCase {
    private const TOKEN = 'test-token-0123456789';
    private const LIMITS = ['disk_bytes' => 100, 'processes' => 10, 'cpu_seconds' => 5, 'memory_bytes' => 1000, 'concurrent_commands' => 1];

    /** @var list<string> */
    public array $commands = [];

    private function server(?FileStore $files = null): ExecutorServer {
        $runner = new class($this) implements Runner {
            public function __construct(private readonly ExecutorServerTest $test) {}

            public function run(int $principalId, SandboxLimits $limits, string $command, string $cwd, int $timeoutSeconds, ?string $stdin, Cancellation $cancellation): CommandResult {
                $this->test->commands[] = $command . ' @' . $cwd;
                if ($command === 'wait') {
                    while (!$cancellation->isRequested()) {
                        delay(0.01);
                    }
                    return new CommandResult(137, '', '', cancelled: true);
                }
                return new CommandResult(0, 'out', 'err', durationMs: 5);
            }
        };
        $files ??= new class implements FileStore {
            public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines): array {
                throw new SandboxError('Не удалось прочитать x: нет файла', 404);
            }

            public function write(int $principalId, SandboxLimits $limits, string $path, string $content): void {
                throw new LogicException('boom /secret/path');
            }

            public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new): void {}

            public function list(int $principalId, SandboxLimits $limits, string $path, int $depth): array {
                return [];
            }

            public function usage(int $principalId): int {
                return 42;
            }
        };
        return new ExecutorServer(self::TOKEN, new Workspace(), $runner, $files);
    }

    /**
     * @param array<string, mixed> $body
     * @param non-empty-string $method
     */
    private function request(string $path, array $body = [], ?string $token = self::TOKEN, string $method = 'POST'): Request {
        $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
        return new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://sandbox:9000' . $path),
            $headers,
            $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }


    /** @return array<string, mixed> */
    private static function json(\Amp\Http\Server\Response $response): array {
        $decoded = json_decode(buffer($response->getBody()), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }

    public function testMissingTokenIs401(): void {
        self::assertSame(401, $this->server()->handleRequest($this->request('/v1/usage', ['principal_id' => 7, 'limits' => self::LIMITS], null))->getStatus());
    }

    public function testWrongTokenIs401(): void {
        self::assertSame(401, $this->server()->handleRequest($this->request('/v1/usage', ['principal_id' => 7, 'limits' => self::LIMITS], 'nope'))->getStatus());
    }

    public function testExecReturnsResult(): void {
        $response = $this->server()->handleRequest($this->request('/v1/exec', ['principal_id' => 7, 'limits' => self::LIMITS, 'command' => 'ls', 'cwd' => 'src', 'timeout' => 5]));

        self::assertSame(200, $response->getStatus());
        $body = self::json($response);
        self::assertTrue($body['ok']);
        self::assertSame('out', $body['stdout']);
        self::assertSame(['ls @/workspace/p7/src'], $this->commands);
    }

    public function testExecRefusesCwdOutsideHome(): void {
        $response = $this->server()->handleRequest($this->request('/v1/exec', ['principal_id' => 7, 'limits' => self::LIMITS, 'command' => 'ls', 'cwd' => '/etc']));

        self::assertSame(403, $response->getStatus());
        self::assertSame([], $this->commands);
    }

    public function testSandboxErrorKeepsStatusAndText(): void {
        $response = $this->server()->handleRequest($this->request('/v1/read', ['principal_id' => 7, 'limits' => self::LIMITS, 'path' => 'x']));

        self::assertSame(404, $response->getStatus());
        self::assertSame(['ok' => false, 'error' => 'Не удалось прочитать x: нет файла'], self::json($response));
    }

    public function testUnexpectedErrorHidesDetails(): void {
        $response = $this->server()->handleRequest($this->request('/v1/write', ['principal_id' => 7, 'limits' => self::LIMITS, 'path' => 'x', 'content' => 'y']));

        self::assertSame(500, $response->getStatus());
        self::assertSame(['ok' => false, 'error' => 'Внутренняя ошибка песочницы.'], self::json($response));
    }

    public function testCancelStopsRunningCall(): void {
        $server = $this->server();
        $future = async(fn() => $server->handleRequest($this->request('/v1/exec', ['principal_id' => 7, 'limits' => self::LIMITS, 'command' => 'wait', 'call_id' => 'c1'])));
        delay(0.05);
        $cancel = $server->handleRequest($this->request('/v1/cancel', ['principal_id' => 7, 'limits' => self::LIMITS, 'call_id' => 'c1']));

        self::assertSame(200, $cancel->getStatus());
        $response = $future->await();
        self::assertInstanceOf(\Amp\Http\Server\Response::class, $response);
        self::assertTrue(self::json($response)['cancelled']);
    }

    /** Review I6: participants' commands share the executor's network namespace and come from loopback. */
    public function testLoopbackPeerIsRefused(): void {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new \Amp\Socket\InternetAddress('127.0.0.1', 50000));
        $request = new Request($client, 'POST', Http::new('http://sandbox:9000/v1/usage'), ['authorization' => 'Bearer ' . self::TOKEN], json_encode(['principal_id' => 7, 'limits' => self::LIMITS], JSON_THROW_ON_ERROR));

        self::assertSame(403, $this->server()->handleRequest($request)->getStatus());
    }

    public function testHealthNeedsNoToken(): void {
        self::assertSame(200, $this->server()->handleRequest($this->request('/health', [], null, 'GET'))->getStatus());
    }

    public function testUsage(): void {
        self::assertSame(['ok' => true, 'bytes' => 42], self::json($this->server()->handleRequest($this->request('/v1/usage', ['principal_id' => 7, 'limits' => self::LIMITS]))));
    }
}
