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

/**
 * A tool of an external MCP server in the agent's registry: the same loop, limits and confirmations as a built-in one.
 * Its class comes from the server's annotations: read-only — a read, not destructive — a write, nothing said — an
 * irreversible call. The result is untrusted data, cleaned of invisible characters.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a tool with no declared danger is irreversible; MCP results are untrusted
 */
final class McpTool implements Tool {
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

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 90;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        try {
            [$text, $error] = $this->client->call($this->server, ($this->token)(), $this->remoteName, $args, $context->cancellation);
        } catch (RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }
        $text = SecretMasker::mask(McpManager::clean($text));
        return $error ? ToolResult::error($text === '' ? 'Инструмент MCP сообщил об ошибке.' : $text) : ToolResult::ok($text === '' ? '(пусто)' : $text);
    }
}
