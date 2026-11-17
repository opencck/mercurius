<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Mcp;

use Amp\Cancellation;
use Amp\Future;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Sandbox\SandboxStreams;
use OpenCCK\App\Mcp\McpRpcClient;
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
 * The token goes into the environment variable named after the secret (`secret:VARIABLE`, MCP_TOKEN by default).
 * One process serves every request to the server; it is started at the first one and again after it ended.
 *
 * spec: BRIEF.md#скиллы-и-mcp — stdio servers in the sandbox, not in `app`
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

    /** @param (Closure(string): void)|null $onToolsChanged a server said its tools changed */
    public function __construct(
        private readonly SandboxStreams $sandbox,
        private readonly SandboxLimits $limits,
        private readonly ?Closure $onToolsChanged = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** The variable the token goes into: the part after ':' in the secret's field, MCP_TOKEN otherwise. */
    public static function envName(?string $secret): string {
        $position = $secret === null ? false : strrpos($secret, ':');
        $name = $secret === null || $position === false ? '' : substr($secret, $position + 1);
        return preg_match('~^[A-Z_][A-Z0-9_]{0,63}$~', $name) === 1 ? $name : 'MCP_TOKEN';
    }

    public function forget(string $server): void {
        $session = $this->sessions[$server] ?? null;
        unset($this->sessions[$server]);
        $session?->close();
    }

    protected function session(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): StdioSession {
        $session = $this->sessions[$server->name] ?? null;
        if ($session !== null && $session->alive()) {
            return $session;
        }
        // concurrent first requests start one process
        $opening = array_key_exists($server->name, $this->opening) ? $this->opening[$server->name] : null;
        if ($opening === null) {
            $opening = async(fn(): StdioSession => $this->open($server, $token));
            $this->opening[$server->name] = $opening;
        }
        try {
            /** @var StdioSession $started */
            $started = $opening->await($cancellation);
            return $started;
        } finally {
            if ($opening->isComplete() && array_key_exists($server->name, $this->opening) && $this->opening[$server->name] === $opening) {
                unset($this->opening[$server->name]);
            }
        }
    }

    protected function rpc(McpServer $server, #[SensitiveParameter] ?string $token, object|string|null $session, string $method, array $params, Cancellation $cancellation): array {
        if (!$session instanceof StdioSession) {
            throw new RuntimeException(t('mcp.stdio.closed'));
        }
        return $session->request($method, $params, $cancellation, self::REQUEST_SECONDS);
    }

    /** @throws RuntimeException */
    private function open(McpServer $server, ?string $token): StdioSession {
        $this->forget($server->name);
        $command = trim(substr($server->url, strlen(self::PREFIX)));
        $env = $token === null ? [] : [self::envName($server->secret) => $token];
        $principal = self::PRINCIPAL_BASE + $server->id;
        try {
            $id = $this->sandbox->spawn($principal, $this->limits, $command, $env, new TimeoutCancellation(60));
        } catch (SandboxFailure $e) {
            throw new RuntimeException(t('mcp.stdio.not_started') . $e->getMessage());
        }
        $session = new StdioSession($this->sandbox, $this->limits, $principal, $id, $server->name, $this->onToolsChanged, $this->logger);
        try {
            $session->request('initialize', self::initializeParams(), new NullCancellation(), self::START_SECONDS);
            $session->notify('notifications/initialized');
        } catch (RuntimeException $e) {
            $session->close();
            throw $e;
        }
        $this->logger->info('MCP stdio server started', ['server' => $server->name]);
        return $this->sessions[$server->name] = $session;
    }
}
