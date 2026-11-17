<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Mcp\McpServer;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * A tool of an external MCP server in the agent's registry: the same loop, limits and confirmations as a built-in one.
 * Its class comes from the server's annotations: read-only — a read, not destructive — a write, nothing said — an
 * irreversible call. The result is untrusted data, cleaned of invisible characters.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a tool with no declared danger is irreversible; MCP results are untrusted
 */
final class McpTool implements Tool, \OpenCCK\App\Agent\Tool\ReadsOutside, \OpenCCK\App\Agent\Tool\GrantKeyed {
    /**
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $annotations
     * @param Closure(): ?string $token the server's bearer token, from the vault, at call time
     */
    public function __construct(
        private readonly McpServer $server,
        private readonly string $remoteName,
        private readonly string $name,
        private readonly string $description,
        private readonly array $schema,
        private readonly array $annotations,
        private readonly McpClient $client,
        private readonly Closure $token,
        private readonly McpBreaker $breaker = new McpBreaker(),
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition($this->name, sprintf('[MCP %s] %s', $this->server->name, $this->description), $this->schema);
    }

    public function danger(array $args): DangerClass {
        if (($this->annotations['readOnlyHint'] ?? null) === true) {
            return DangerClass::Read;
        }
        if (($this->annotations['destructiveHint'] ?? null) === false) {
            return DangerClass::Write;
        }
        return DangerClass::Irreversible;
    }

    /** spec: BRIEF.md#подтверждения-и-запреты — mail and other services bring text written by others */
    public function readsOutside(array $args): bool {
        return true;
    }

    /**
     * spec: BRIEF.md#подтверждения-и-запреты — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    /** @return non-empty-string */
    public function grantKey(array $args): string {
        return sprintf('mcp:%s:%s', $this->server->name, $this->remoteName);
    }

    public function describe(array $args): string {
        return sprintf('[MCP %s] %s', $this->server->name, $this->remoteName);
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 90;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $refusal = $this->breaker->refusal($this->server->name);
        if ($refusal !== null) {
            return ToolResult::error($refusal);
        }
        try {
            [$text, $error] = $this->client->call($this->server, ($this->token)(), $this->remoteName, $args, $context->cancellation);
        } catch (RuntimeException $e) {
            $this->breaker->failed($this->server->name, false);
            return ToolResult::error($e->getMessage());
        }
        if ($error) {
            $this->breaker->failed($this->server->name, true);
        } else {
            $this->breaker->succeeded($this->server->name);
        }
        $text = SecretMasker::mask(McpManager::clean($text));
        return $error ? ToolResult::error($text === '' ? t('mcp.mcp_tool.instrument_mcp_soobschil_ob_oshibke') : $text) : ToolResult::ok($text === '' ? t('mcp.mcp_tool.pusto') : $text);
    }
}
