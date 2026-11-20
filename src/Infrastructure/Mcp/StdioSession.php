<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Mcp;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Sandbox\SandboxStreams;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\Domain\Agent\SandboxLimits;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * One stdio MCP server process in the sandbox, as JSON-RPC: requests wait for the answer with their id, lines are
 * read all the time in their own fiber, whatever comes — answers, notifications, requests of the server — so the
 * process never stalls on a full pipe. A process that ended fails everything that waits, with the tail of its stderr.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — stdio servers in the sandbox
 * ref: .claude/skills/skill-amphp/docs/common-mistakes.md (18. receive buffer deadlock, 19. Future::ignore) — the
 *      output is always drained; background fibers are ignored so their failures do not bring the process down
 */
final class StdioSession {
    /** A line longer than this is not JSON-RPC from a sane server: the session is closed. */
    private const LINE_MAX = 8 * 1024 * 1024;
    private const POLL_SECONDS = 20;

    private int $nextId = 1;
    /** @var array<int, DeferredFuture<array<array-key, mixed>>> */
    private array $pending = [];
    private string $buffer = '';
    private bool $closed = false;
    private string $failure = '';

    /** @param (Closure(string): void)|null $onToolsChanged the server said its tools changed */
    public function __construct(
        private readonly SandboxStreams $sandbox,
        private readonly SandboxLimits $limits,
        private readonly int $principalId,
        private readonly string $id,
        private readonly string $server,
        private readonly ?Closure $onToolsChanged,
        private readonly LoggerInterface $logger,
    ) {
        async($this->read(...))->ignore();
    }

    public function alive(): bool {
        return !$this->closed;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     * @throws RuntimeException
     */
    public function request(string $method, array $params, Cancellation $cancellation, float $timeout): array {
        if ($this->closed) {
            throw new RuntimeException($this->failure);
        }
        $id = $this->nextId++;
        /** @var DeferredFuture<array<array-key, mixed>> $deferred */
        $deferred = new DeferredFuture();
        $this->pending[$id] = $deferred;
        try {
            $this->write(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
            return $deferred->getFuture()->await(new CompositeCancellation($cancellation, new TimeoutCancellation($timeout)));
        } catch (CancelledException) {
            throw new RuntimeException(sprintf(t('mcp.stdio.timeout'), $method));
        } finally {
            unset($this->pending[$id]);
        }
    }

    /** @throws RuntimeException */
    public function notify(string $method): void {
        $this->write(['jsonrpc' => '2.0', 'method' => $method]);
    }

    public function close(): void {
        if (!$this->closed) {
            $this->fail(t('mcp.stdio.closed'));
        }
        $this->sandbox->close($this->principalId, $this->limits, $this->id);
    }

    /**
     * @param array<string, mixed> $message
     * @throws RuntimeException
     */
    private function write(array $message): void {
        try {
            $this->sandbox->send($this->principalId, $this->limits, $this->id, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", new TimeoutCancellation(30));
        } catch (SandboxFailure | CancelledException $e) {
            $this->fail(t('mcp.stdio.ended_short') . $e->getMessage());
            throw new RuntimeException($this->failure);
        }
    }

    private function read(): void {
        while (!$this->closed) {
            try {
                $got = $this->sandbox->receive($this->principalId, $this->limits, $this->id, self::POLL_SECONDS, new NullCancellation());
            } catch (Throwable $e) {
                $this->fail(t('mcp.stdio.ended_short') . $e->getMessage());
                return;
            }
            $this->buffer .= $got['stdout'];
            while (($end = strpos($this->buffer, "\n")) !== false) {
                $line = trim(substr($this->buffer, 0, $end));
                $this->buffer = substr($this->buffer, $end + 1);
                if ($line !== '') {
                    $this->dispatch($line);
                }
            }
            if (strlen($this->buffer) > self::LINE_MAX) {
                $this->fail(t('mcp.stdio.line_too_long'));
                $this->sandbox->close($this->principalId, $this->limits, $this->id);
                return;
            }
            if ($got['exited']) {
                $tail = trim(mb_substr(McpManager::clean($got['stderr']), -600));
                $this->fail(sprintf(t('mcp.stdio.ended'), $got['exitCode'] ?? -1) . ($tail === '' ? '' : ': ' . $tail));
                return;
            }
        }
    }

    private function dispatch(string $line): void {
        try {
            /** @var mixed $message */
            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // a server that logs to stdout: not a message
            $this->logger->debug('MCP stdio line is not JSON', ['server' => $this->server]);
            return;
        }
        if (!is_array($message)) {
            return;
        }
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            if (!is_int($id)) {
                return;
            }
            $waiting = $this->pending[$id] ?? null;
            if ($waiting === null) {
                return;
            }
            unset($this->pending[$id]);
            if (isset($message['error']) && is_array($message['error'])) {
                $text = isset($message['error']['message']) && is_string($message['error']['message']) ? $message['error']['message'] : t('mcp.http_mcp_client.oshibka');
                $waiting->error(new RuntimeException(t('mcp.http_mcp_client.mcp_server_otvetil_oshibkoy') . mb_substr($text, 0, 300)));
                return;
            }
            $waiting->complete(isset($message['result']) && is_array($message['result']) ? $message['result'] : []);
            return;
        }
        if ($id !== null) {
            // a request of the server: ping is answered, nothing else is offered
            $reply = $method === 'ping'
                ? ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []]
                : ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']];
            async(function () use ($reply): void {
                try {
                    $this->write($reply);
                } catch (RuntimeException) {
                    // the session is failing already
                }
            })->ignore();
            return;
        }
        if ($method === 'notifications/tools/list_changed' && $this->onToolsChanged !== null) {
            ($this->onToolsChanged)($this->server);
        }
    }

    private function fail(string $why): void {
        $this->closed = true;
        $this->failure = $why;
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $deferred) {
            $deferred->error(new RuntimeException($why));
        }
    }
}
