<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Infrastructure\Mcp\StdioMcpClient;
use OpenCCK\Test\Support\FakeSandboxStreams;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

/**
 * MCP over stdio of a process in the sandbox: one long-lived session per server, JSON-RPC lines, answers matched by
 * id, notifications and requests of the server handled, a dead process brings an error and a new one next time.
 */
final class StdioMcpClientTest extends AsyncTestCase {
    private FakeSandboxStreams $sandbox;
    private StdioMcpClient $client;
    /** @var list<string> servers whose tools changed */
    private array $changed = [];

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandboxStreams();
        $this->client = new StdioMcpClient($this->sandbox, new SandboxLimits(100 * 1024 ** 2, 64, 3600, 2 * 1024 ** 3, 4), function (string $server): void {
            $this->changed[] = $server;
        });
    }

    protected function tearDown(): void {
        $this->client->forget('everything');
        parent::tearDown();
    }

    private static function server(?string $secret = null): McpServer {
        return new McpServer(7, 'everything', 'stdio:npx -y @modelcontextprotocol/server-everything', $secret, McpServer::ACTIVE, null);
    }

    public function testOneProcessServesTheServerAndStartsWithAHandshake(): void {
        $tools = $this->client->tools(self::server(), null, new NullCancellation());
        [$text, $error] = $this->client->call(self::server(), null, 'echo', ['q' => 'hi'], new NullCancellation());

        self::assertSame('echo', $tools[0]['name'] ?? null);
        self::assertSame('echo:{"name":"echo","arguments":{"q":"hi"}}', $text);
        self::assertFalse($error);
        self::assertCount(1, $this->sandbox->spawned, 'one process for every request');
        [$principal, $command, $env] = $this->sandbox->spawned[0];
        self::assertSame(StdioMcpClient::PRINCIPAL_BASE + 7, $principal, 'a sandbox user of its own, not the owner\'s');
        self::assertSame('npx -y @modelcontextprotocol/server-everything', $command);
        self::assertSame([], $env);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list', 'tools/call'], array_column($this->sandbox->written, 'method'));
    }

    public function testTheTokenGoesIntoTheNamedVariable(): void {
        $this->client->tools(self::server('gh-token:GITHUB_PERSONAL_ACCESS_TOKEN'), 'ghp_secret', new NullCancellation());
        $this->client->forget('everything');
        $this->client->tools(new McpServer(8, 'other', 'stdio:node server.js', 'plain', McpServer::ACTIVE, null), 'tok', new NullCancellation());
        $this->client->forget('other');

        self::assertSame(['GITHUB_PERSONAL_ACCESS_TOKEN' => 'ghp_secret'], $this->sandbox->spawned[0][2]);
        self::assertSame(['MCP_TOKEN' => 'tok'], $this->sandbox->spawned[1][2], 'without a name the token is MCP_TOKEN');
    }

    public function testCallsAtOnceGetTheirOwnAnswers(): void {
        $this->sandbox->callDelay = 0.1;
        $calls = [];
        foreach (['a', 'b', 'c'] as $q) {
            $calls[] = async(fn(): array => $this->client->call(self::server(), null, 'echo', ['q' => $q], new NullCancellation()));
        }

        $texts = [];
        foreach ($calls as $call) {
            /** @var array{string, bool} $result */
            $result = $call->await();
            $texts[] = $result[0];
        }

        self::assertSame(['echo:{"name":"echo","arguments":{"q":"a"}}', 'echo:{"name":"echo","arguments":{"q":"b"}}', 'echo:{"name":"echo","arguments":{"q":"c"}}'], $texts);
        self::assertCount(1, $this->sandbox->spawned, 'concurrent first calls start one process');
    }

    /** spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — a request of the server for consent */
    public function testTheServerMayAskForConsentAndHearsTheAnswer(): void {
        $asked = new \ArrayObject();
        $this->client->useElicitation(static function (string $server, array $params) use ($asked): array {
            $asked->append([$server, $params['message'] ?? null]);
            return ['action' => 'accept', 'content' => ['confirm' => true]];
        });
        $this->client->tools(self::server(), null, new NullCancellation());

        $this->sandbox->push('s1', '{"jsonrpc":"2.0","id":9,"method":"elicitation/create","params":{"message":"Confirm touch","requestedSchema":{"type":"object","properties":{"confirm":{"type":"boolean"}}}}}');
        delay(0.3);

        self::assertSame([['everything', 'Confirm touch']], $asked->getArrayCopy());
        $reply = array_values(array_filter($this->sandbox->written, static fn(array $m): bool => ($m['id'] ?? null) === 9));
        self::assertSame([['jsonrpc' => '2.0', 'id' => 9, 'result' => ['action' => 'accept', 'content' => ['confirm' => true]]]], $reply);
        $init = array_values(array_filter($this->sandbox->written, static fn(array $m): bool => ($m['method'] ?? null) === 'initialize'));
        self::assertStringContainsString('"elicitation"', (string) json_encode($init[0] ?? []), 'the client says it can ask');
    }

    /** @param \Closure(string, array<array-key, mixed>, \Amp\Cancellation): array<string, mixed> $elicit */
    private function session(\Closure $elicit): \OpenCCK\Infrastructure\Mcp\StdioSession {
        $id = $this->sandbox->spawn(900007, new SandboxLimits(1, 1, 1, 1, 1), 'x', [], new NullCancellation());
        return new \OpenCCK\Infrastructure\Mcp\StdioSession($this->sandbox, new SandboxLimits(1, 1, 1, 1, 1), 900007, $id, 'member:1|ssh', null, new \Psr\Log\NullLogger(), $elicit);
    }

    private const CONSENT = '{"jsonrpc":"2.0","id":%d,"method":"elicitation/create","params":{"message":"Confirm","requestedSchema":{"type":"object","properties":{"confirm":{"type":"boolean"}}}}}';

    /** spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — the call waits while the owner decides */
    public function testACallWaitsWhileItsConsentIsAsked(): void {
        $this->sandbox->callDelay = 0.6;
        $session = $this->session(static function (string $server, array $params): array {
            delay(0.5);
            return ['action' => 'accept', 'content' => ['confirm' => true]];
        });
        $this->sandbox->push('s1', sprintf(self::CONSENT, 9));

        $result = $session->request('tools/call', ['name' => 'echo'], new NullCancellation(), 0.2);

        self::assertArrayHasKey('content', $result, 'not given up while the card was open');
        $session->close();
    }

    public function testASecondConsentWhileOneIsOpenIsDeclined(): void {
        $asked = new \ArrayObject();
        $session = $this->session(static function (string $server, array $params) use ($asked): array {
            $asked->append($server);
            delay(0.3);
            return ['action' => 'decline'];
        });
        $this->sandbox->push('s1', sprintf(self::CONSENT, 9) . "\n" . sprintf(self::CONSENT, 10));
        delay(0.15);

        self::assertCount(1, $asked, 'one card at a time');
        $declined = array_values(array_filter($this->sandbox->written, static fn(array $m): bool => ($m['id'] ?? null) === 10));
        self::assertSame([['jsonrpc' => '2.0', 'id' => 10, 'result' => ['action' => 'decline']]], $declined);
        delay(0.3);
        $session->close();
    }

    public function testAClosedSessionClosesItsConsent(): void {
        $ended = new \ArrayObject();
        $session = $this->session(static function (string $server, array $params, \Amp\Cancellation $cancellation) use ($ended): array {
            try {
                delay(5, cancellation: $cancellation);
            } catch (\Amp\CancelledException) {
                $ended->append('cancelled');
            }
            return ['action' => 'cancel'];
        });
        $this->sandbox->push('s1', sprintf(self::CONSENT, 9));
        delay(0.1);

        $session->close();
        delay(0.05);

        self::assertSame(['cancelled'], $ended->getArrayCopy());
    }

    public function testAProcessWaitingForConsentIsNotIdle(): void {
        $this->client->useElicitation(static function (string $server, array $params): array {
            delay(0.5);
            return ['action' => 'decline'];
        });
        $this->client->tools(self::server(), null, new NullCancellation());
        $this->sandbox->push('s1', sprintf(self::CONSENT, 9));
        delay(0.1);

        self::assertSame(0, $this->client->stopIdle(microtime(true) + StdioMcpClient::IDLE_SECONDS + 1.0), 'a card is open');
        delay(0.5);
    }

    /** spec: brief/skills-mcp.md#установка-и-обновление — the trial start runs as the install's own user and ends */
    public function testATrialRunsAsTheInstallAndClosesItsProcess(): void {
        $tools = $this->client->trial(800003, self::server(), new NullCancellation());

        self::assertSame('echo', $tools[0]['name'] ?? null);
        self::assertSame(800003, $this->sandbox->spawned[0][0] ?? null);
        self::assertTrue($this->sandbox->closed['s1'] ?? false, 'nothing of the trial keeps running');
        $this->client->tools(self::server(), null, new NullCancellation());
        self::assertSame(StdioMcpClient::PRINCIPAL_BASE + 7, $this->sandbox->spawned[1][0] ?? null, 'a later start is the connection again');
    }

    /** spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — stopped when idle, started on demand */
    public function testAnIdleProcessStopsAndTheNextRequestStartsItAgain(): void {
        $this->client->tools(self::server(), null, new NullCancellation());

        self::assertSame(0, $this->client->stopIdle(microtime(true) + 60.0), 'busy a minute ago: kept');
        self::assertSame(1, $this->client->stopIdle(microtime(true) + StdioMcpClient::IDLE_SECONDS + 1.0));
        self::assertTrue($this->sandbox->closed['s1'] ?? false);

        $this->client->call(self::server(), null, 'echo', ['q' => 'again'], new NullCancellation());
        self::assertCount(2, $this->sandbox->spawned, 'a new process for the next request');
    }

    /** the processes a restarted application left behind are closed before a new one starts */
    public function testTheProcessesLeftBehindAreClosedBeforeAStart(): void {
        $this->client->tools(self::server(), null, new NullCancellation());

        self::assertSame([StdioMcpClient::PRINCIPAL_BASE + 7], $this->sandbox->closedAll);
    }

    public function testTheServerMayNotifyAndAsk(): void {
        $this->client->tools(self::server(), null, new NullCancellation());

        $this->sandbox->push('s1', '{"jsonrpc":"2.0","method":"notifications/tools/list_changed"}');
        $this->sandbox->push('s1', '{"jsonrpc":"2.0","id":"p1","method":"ping"}');
        $this->sandbox->push('s1', '{"jsonrpc":"2.0","id":"r1","method":"roots/list"}');
        delay(0.3);

        self::assertSame(['everything'], $this->changed);
        $replies = array_values(array_filter($this->sandbox->written, static fn(array $m): bool => in_array($m['id'] ?? null, ['p1', 'r1'], true)));
        self::assertSame([['jsonrpc' => '2.0', 'id' => 'p1', 'result' => []], ['jsonrpc' => '2.0', 'id' => 'r1', 'error' => ['code' => -32601, 'message' => 'Method not found']]], $replies);
    }

    public function testADeadProcessFailsWhatWaitsAndANewOneStartsNextTime(): void {
        $this->client->tools(self::server(), null, new NullCancellation());
        $this->sandbox->callDelay = 1.0;
        $waiting = async(fn(): array => $this->client->call(self::server(), null, 'echo', [], new NullCancellation()));
        delay(0.1);
        $this->sandbox->end('s1', 1, "Error: Cannot find module 'x'\n");

        try {
            $waiting->await();
            self::fail('a dead process answered');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("Cannot find module 'x'", $e->getMessage(), 'stderr tells why');
        }
        $this->sandbox->callDelay = 0.0;
        self::assertSame('echo', $this->client->tools(self::server(), null, new NullCancellation())[0]['name'] ?? null);
        self::assertCount(2, $this->sandbox->spawned);
    }

    public function testAnErrorOfTheServerIsAnError(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Method not found');

        $this->client->resources(self::server(), null, new NullCancellation());
    }

    public function testForgettingTheServerStopsItsProcess(): void {
        $this->client->tools(self::server(), null, new NullCancellation());

        $this->client->forget('everything');

        self::assertTrue($this->sandbox->closed['s1'] ?? false);
    }
}
