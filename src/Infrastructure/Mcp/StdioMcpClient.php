<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Mcp;

use Mercurius\Mcp\McpEndpoint;
use Amp\Cancellation;
use Amp\Future;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Sandbox\SandboxStreams;
use Mercurius\Mcp\McpRpcClient;
use OpenCCK\App\Mcp\McpSessions;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Mcp\McpServer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SensitiveParameter;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * MCP over stdio of a process in the sandbox. The server's url is `stdio:<command>`; the process runs as a sandbox user
 * of its own (PRINCIPAL_BASE + the server's id), not the owner's, so the owner's commands cannot read its environment.
 * A connection of an entry starts with the environment of the entry, its secrets put in; a connection kept before
 * entries were stored puts its token into the variable named after the secret (`secret:VARIABLE`, MCP_TOKEN by
 * default). One process serves every request to the server; it is started at the first one, again after it ended, and
 * again when the entry with its secrets put in is another (a secret changed).
 *
 * spec: brief/skills-mcp.md#подстановка-секретов — another value of a secret is another process
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — stdio servers in the sandbox, not in `app`
 * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L199-L221 (_build_safe_env) — a filtered environment
 *      plus the server's own variables; here the sandbox starts from an empty one
 */
final class StdioMcpClient extends McpRpcClient implements McpSessions {
    public const PREFIX = 'stdio:';
    public const PRINCIPAL_BASE = 900_000;
    /** The first start may download the package (npx): the handshake waits longer than a request. */
    private const START_SECONDS = 180.0;
    private const REQUEST_SECONDS = 120.0;

    /** @var array<string, StdioSession> by server name */
    private array $sessions = [];
    /** @var array<string, Future<StdioSession>> starts in progress */
    private array $opening = [];
    /** @var array<string, string> by server name: what the running process was started with */
    private array $started = [];
    /** @var array<string, float> by server name: when its process was last asked */
    private array $used = [];
    /** a process nobody asked for this long is stopped; the next request starts it again */
    public const IDLE_SECONDS = 600.0;

    /** @param (Closure(string): void)|null $onToolsChanged a server said its tools changed */
    public function __construct(
        private readonly SandboxStreams $sandbox,
        private readonly SandboxLimits $limits,
        private readonly ?Closure $onToolsChanged = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        \Mercurius\Mcp\McpTexts $texts = new \Mercurius\Mcp\McpTexts(),
    ) {
        parent::__construct($texts);
    }

    /** @var (Closure(string, array<array-key, mixed>, \Amp\Cancellation): array<string, mixed>)|null */
    private ?Closure $elicit = null;

    /**
     * Requests of the servers for consent go to the owner of their entry: the key of the connection and the request
     * in, the result of elicitation/create out. The client then says at the handshake that it can ask.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
     *
     * @param Closure(string, array<array-key, mixed>, \Amp\Cancellation): array<string, mixed> $elicit
     */
    public function useElicitation(Closure $elicit): void {
        $this->elicit = $elicit;
    }

    protected function initializeParams(): array {
        $params = parent::initializeParams();
        if ($this->elicit !== null) {
            $params['capabilities'] = ['elicitation' => (object) []];
        }
        return $params;
    }

    /** The variable the token goes into: the part after ':' in the secret's field, MCP_TOKEN otherwise. */
    public static function envName(?string $secret): string {
        $position = $secret === null ? false : strrpos($secret, ':');
        $name = $secret === null || $position === false ? '' : substr($secret, $position + 1);
        return preg_match('~^[A-Z_][A-Z0-9_]{0,63}$~', $name) === 1 ? $name : 'MCP_TOKEN';
    }

    /**
     * Stops the processes nobody asked for IDLE_SECONDS; their tools stay registered, the next request starts them.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — started on demand, stopped when idle
     *
     * @return int how many were stopped
     */
    public function stopIdle(?float $now = null): int {
        $now ??= microtime(true);
        $stopped = 0;
        foreach ($this->used as $server => $at) {
            // a request still waiting or a card still open is not idle, however long ago it began
            if (!isset($this->sessions[$server])) {
                // stopped or forgotten already: nothing to watch
                unset($this->used[$server]);
            } elseif ($now - $at >= self::IDLE_SECONDS && !$this->sessions[$server]->busy()) {
                $this->forget($server);
                $stopped++;
            }
        }
        return $stopped;
    }

    /** @var array<string, int> by server key: the sandbox user a trial start runs as instead of the connection's */
    private array $as = [];

    /**
     * A trial start of an install: its tools listed by the install's own user, then the process closed.
     *
     * spec: brief/skills-mcp.md#установка-и-обновление
     *
     * @return list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>
     * @throws RuntimeException
     */
    public function trial(int $principal, McpServer $server, Cancellation $cancellation): array {
        $this->as[$server->key()] = $principal;
        try {
            return $this->tools($server, null, $cancellation);
        } finally {
            $this->forget($server->key());
            unset($this->as[$server->key()]);
        }
    }

    public function idle(string $server): bool {
        $session = $this->sessions[$server] ?? null;
        return $session === null || !$session->alive();
    }

    public function forget(string $server): void {
        $session = $this->sessions[$server] ?? null;
        unset($this->sessions[$server]);
        $session?->close();
    }

    protected function session(McpEndpoint $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): StdioSession {
        // a stdio server is one of this application: its number and secret name the user and the variable
        if (!$server instanceof McpServer) {
            throw new \RuntimeException('stdio needs a registered server');
        }
        $this->used[$server->key()] = microtime(true);
        $session = $this->sessions[$server->key()] ?? null;
        if ($session !== null && $session->alive() && ($this->started[$server->key()] ?? '') === self::startedWith($server)) {
            return $session;
        }
        // concurrent first requests start one process
        $opening = array_key_exists($server->key(), $this->opening) ? $this->opening[$server->key()] : null;
        if ($opening === null) {
            $opening = async(fn(): StdioSession => $this->open($server, $token));
            $this->opening[$server->key()] = $opening;
        }
        try {
            /** @var StdioSession $started */
            $started = $opening->await($cancellation);
            return $started;
        } finally {
            if ($opening->isComplete() && array_key_exists($server->key(), $this->opening) && $this->opening[$server->key()] === $opening) {
                unset($this->opening[$server->key()]);
            }
        }
    }

    protected function rpc(McpEndpoint $server, #[SensitiveParameter] ?string $token, object|string|null $session, string $method, array $params, Cancellation $cancellation): array {
        if (!$session instanceof StdioSession) {
            throw new RuntimeException(t('mcp.stdio.closed'));
        }
        return $session->request($method, $params, $cancellation, self::REQUEST_SECONDS);
    }

    /** @throws RuntimeException */
    private function open(McpServer $server, ?string $token): StdioSession {
        $this->forget($server->key());
        $command = trim(substr($server->url, strlen(self::PREFIX)));
        $env = $server->renderedHash !== null ? $server->env : ($token === null ? [] : [self::envName($server->secret) => $token]);
        $principal = $this->as[$server->key()] ?? self::PRINCIPAL_BASE + $server->id;
        // what a restarted application left running for this server goes first
        $this->sandbox->closeAll($principal, $this->limits);
        try {
            $id = $this->sandbox->spawn($principal, $this->limits, $command, $env, new TimeoutCancellation(60));
        } catch (SandboxFailure $e) {
            throw new RuntimeException(t('mcp.stdio.not_started') . $e->getMessage());
        }
        $session = new StdioSession($this->sandbox, $this->limits, $principal, $id, $server->key(), $this->onToolsChanged, $this->logger, $this->elicit);
        try {
            $session->request('initialize', $this->initializeParams(), new NullCancellation(), self::START_SECONDS);
            $session->notify('notifications/initialized');
        } catch (RuntimeException $e) {
            $session->close();
            throw $e;
        }
        $this->logger->info('MCP stdio server started', ['server' => $server->key()]);
        $this->started[$server->key()] = self::startedWith($server);
        return $this->sessions[$server->key()] = $session;
    }

    /** What tells one start from another: the entry with its secrets put in, or the command of an older connection. */
    private static function startedWith(McpServer $server): string {
        return $server->renderedHash ?? $server->url;
    }
}
