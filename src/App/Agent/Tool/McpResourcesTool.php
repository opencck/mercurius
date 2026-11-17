<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\Domain\Agent\DangerClass;
use DomainException;

use function Mercurius\I18n\t;

/**
 * Resources of a connected MCP server: without uri — their list, with uri — the text of one. Reading is a read; the
 * text is someone else's data, cleaned of invisible characters and marked untrusted.
 *
 * spec: BRIEF.md#скиллы-и-mcp — reading the resources of MCP servers
 * ref: references/hermes-agent/tools/mcp_tool_handlers.py@e96221fa:L1022-L1027 (list_resources, read_resource) — two
 *      utilities per server in hermes; here one tool takes the server by name
 */
final class McpResourcesTool implements Tool, ReadsOutside {
    public const NAME = 'mcp_resources';

    public function __construct(private readonly McpManager $manager) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.mcp_resources.about'), [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'uri' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048, 'description' => t('tool.mcp_resources.uri')],
            ],
            'required' => ['server'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $server = Args::string($args, 'server') ?? '';
        $uri = Args::string($args, 'uri');
        try {
            if ($uri !== null) {
                return ToolResult::ok($this->manager->readResource($server, $uri, $context->cancellation));
            }
            $lines = [];
            foreach ($this->manager->resources($server, $context->cancellation) as $resource) {
                $lines[] = trim(sprintf('%s — %s%s%s', $resource['uri'], $resource['name'], $resource['mimeType'] === '' ? '' : ' (' . $resource['mimeType'] . ')', $resource['description'] === '' ? '' : ': ' . $resource['description']));
            }
            return ToolResult::ok($lines === [] ? t('tool.mcp_resources.none') : implode("\n", array_slice($lines, 0, 200)));
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
