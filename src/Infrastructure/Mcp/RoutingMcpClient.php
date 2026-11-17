<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Mcp;

use Amp\Cancellation;
use OpenCCK\App\Mcp\McpClient;
use OpenCCK\App\Mcp\McpSessions;
use OpenCCK\Domain\Mcp\McpServer;
use RuntimeException;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * The client of a server by its address: `stdio:` — a process in the sandbox, otherwise HTTP.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 */
final class RoutingMcpClient implements McpClient, McpSessions {
    public function __construct(
        private readonly McpClient $http,
        /** null — the sandbox is off, stdio servers are not available */
        private readonly ?McpClient $stdio = null,
    ) {}

    public function tools(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array {
        return $this->of($server)->tools($server, $token, $cancellation);
    }

    public function call(McpServer $server, #[SensitiveParameter] ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
        return $this->of($server)->call($server, $token, $tool, $arguments, $cancellation);
    }

    public function resources(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array {
        return $this->of($server)->resources($server, $token, $cancellation);
    }

    public function readResource(McpServer $server, #[SensitiveParameter] ?string $token, string $uri, Cancellation $cancellation): string {
        return $this->of($server)->readResource($server, $token, $uri, $cancellation);
    }

    public function forget(string $server): void {
        if ($this->stdio instanceof McpSessions) {
            $this->stdio->forget($server);
        }
    }

    /** @throws RuntimeException */
    private function of(McpServer $server): McpClient {
        if (!str_starts_with($server->url, StdioMcpClient::PREFIX)) {
            return $this->http;
        }
        return $this->stdio ?? throw new RuntimeException(t('mcp.mcp_manager.stdio_off'));
    }
}
