<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Sandbox;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\SocketException;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Closure;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Infrastructure\Sandbox\HttpSandboxClient;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

final class HttpSandboxClientTest extends AsyncTestCase {
    private SandboxHttpRecorder $http;

    /** @param Closure(string): array{int, string} $answer path => [status, json body]; status 0 — network error, -1 — hang */
    private function client(Closure $answer): HttpSandboxClient {
        $this->http = new SandboxHttpRecorder($answer);
        return new HttpSandboxClient($this->http, 'http://sandbox:9000', 'tok-0123456789abcdef');
    }

    private static function limits(): SandboxLimits {
        return new SandboxLimits(100, 10, 5, 1000, 1);
    }

    public function testExecSendsTokenAndParsesOutcome(): void {
        $client = $this->client(static fn(string $path): array => [200, '{"ok":true,"exit_code":3,"stdout":"o","stderr":"e","timed_out":false,"cancelled":false,"duration_ms":7,"over_quota":true}']);

        $outcome = $client->exec(7, self::limits(), 'c1', 'ls', 'src', 30, new NullCancellation());

        self::assertSame(3, $outcome->exitCode);
        self::assertSame('o', $outcome->stdout);
        self::assertTrue($outcome->overQuota);
        [$path, $auth, $body] = $this->http->sent[0];
        self::assertSame('/v1/exec', $path);
        self::assertSame('Bearer tok-0123456789abcdef', $auth);
        self::assertSame(['principal_id' => 7, 'call_id' => 'c1', 'limits' => ['disk_bytes' => 100, 'processes' => 10, 'cpu_seconds' => 5, 'memory_bytes' => 1000, 'concurrent_commands' => 1], 'command' => 'ls', 'cwd' => 'src', 'timeout' => 30], $body);
    }

    public function testErrorBecomesFailureWithText(): void {
        $client = $this->client(static fn(string $path): array => [404, '{"ok":false,"error":"Не удалось прочитать x"}']);

        $this->expectException(SandboxFailure::class);
        $this->expectExceptionMessage('Не удалось прочитать x');
        $client->read(7, self::limits(), 'x', 1, 10, new NullCancellation());
    }

    public function testNetworkErrorIsUnavailable(): void {
        $client = $this->client(static fn(string $path): array => [0, '']);

        $this->expectException(SandboxFailure::class);
        $this->expectExceptionMessage('Песочница недоступна.');
        $client->list(7, self::limits(), '.', 1, new NullCancellation());
    }

    public function testCancelSendsCancelRequest(): void {
        $client = $this->client(static fn(string $path): array => $path === '/v1/exec' ? [-1, '{}'] : [200, '{"ok":true,"cancelled":true}']);
        $deferred = new DeferredCancellation();
        $future = async(fn() => $client->exec(7, self::limits(), 'c9', 'sleep 100', '.', 60, $deferred->getCancellation()));
        delay(0.05);
        $deferred->cancel();

        try {
            $future->await();
            self::fail('Not cancelled');
        } catch (CancelledException) {
        }
        self::assertSame('/v1/cancel', $this->http->sent[1][0]);
        self::assertSame('c9', $this->http->sent[1][2]['call_id']);
    }

    public function testReadReturnsTextAndTotal(): void {
        $client = $this->client(static fn(string $path): array => [200, '{"ok":true,"text":"a\nb\n","totalLines":9}']);

        self::assertSame(['text' => "a\nb\n", 'totalLines' => 9], $client->read(7, self::limits(), 'f', 1, 2, new NullCancellation()));
    }
}

/** Records requests to the executor and answers from a table. */
final class SandboxHttpRecorder implements DelegateHttpClient {
    /** @var list<array{string, ?string, array<array-key, mixed>}> path, authorization, body */
    public array $sent = [];

    /** @param Closure(string): array{int, string} $answer */
    public function __construct(private readonly Closure $answer) {}

    public function request(Request $request, Cancellation $cancellation): Response {
        $path = $request->getUri()->getPath();
        /** @var mixed $body */
        $body = json_decode(buffer($request->getBody()->getContent()), true, 16, JSON_THROW_ON_ERROR);
        $this->sent[] = [$path, $request->getHeader('authorization'), is_array($body) ? $body : []];
        [$status, $json] = ($this->answer)($path);
        if ($status === 0) {
            throw new SocketException('connection refused');
        }
        if ($status === -1) {
            delay(5, cancellation: $cancellation);
        }
        return new Response('1.1', max(200, $status), null, ['content-type' => 'application/json'], $json, $request);
    }
}
