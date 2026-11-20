<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * Two of the bridge tools to MCP tools kept out of the request: `tool_search` finds them by words, `tool_describe`
 * gives their schemas. Only tools the participant may use are seen. The third, `tool_call`, is not a tool: the
 * validator turns it into a call of the tool it names.
 *
 * spec: brief/skills-mcp.md#mcp-ресурсы-и-поиск-инструментов — the search of MCP tools
 * ref: references/hermes-agent/tools/tool_search.py@e96221fa:L38-L39, L442-L546 (dispatch_tool_search,
 *      dispatch_tool_describe) — at most 7 queries and 10 names a call, matches by query with the records of the tools
 *      once, names not found with a hint to search again
 */
final class ToolSearchTool implements Tool {
    public const SEARCH = 'tool_search';
    public const DESCRIBE = 'tool_describe';
    public const MAX_QUERIES = 7;
    public const MAX_NAMES = 10;
    private const DEFAULT_LIMIT = 5;

    public function __construct(
        private readonly string $mode,
        private readonly ToolRegistry $registry,
    ) {
        if ($mode !== self::SEARCH && $mode !== self::DESCRIBE) {
            throw new \InvalidArgumentException('Unknown bridge tool ' . $mode);
        }
    }

    public function definition(): ToolDefinition {
        foreach (ToolSearch::bridge(0) as $definition) {
            if ($definition->name === $this->mode) {
                return $definition;
            }
        }
        throw new \LogicException('No bridge definition for ' . $this->mode);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        // descriptions of MCP tools come from their servers
        return true;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $deferred = [];
        foreach ($context->policy === null ? [] : $this->registry->definitions($context->policy) as $definition) {
            if (ToolSearch::deferrable($definition->name)) {
                $deferred[$definition->name] = $definition;
            }
        }
        return $this->mode === self::SEARCH ? $this->search($args, array_values($deferred)) : $this->describe($args, $deferred);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<ToolDefinition> $deferred
     */
    private function search(array $args, array $deferred): ToolResult {
        $queries = array_slice(self::strings($args['queries'] ?? []), 0, self::MAX_QUERIES);
        if ($queries === []) {
            return ToolResult::error(t('tool.tool_search.no_queries'));
        }
        /** @var mixed $limit */
        $limit = $args['limit'] ?? null;
        $limit = is_int($limit) ? max(1, min(20, $limit)) : self::DEFAULT_LIMIT;
        $results = [];
        $tools = [];
        foreach ($queries as $query) {
            $hits = ToolSearch::search($deferred, $query, $limit);
            $group = ['query' => $query, 'matches' => array_map(static fn(ToolDefinition $d): string => $d->name, $hits)];
            if ($hits === [] && $deferred !== []) {
                $group['hint'] = t('tool.tool_search.no_matches');
            }
            $results[] = $group;
            foreach ($hits as $hit) {
                $tools[$hit->name] ??= mb_substr($hit->description, 0, 500);
            }
        }
        return ToolResult::ok(self::json(['total_available' => count($deferred), 'results' => $results, 'tools' => $tools]));
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, ToolDefinition> $deferred
     */
    private function describe(array $args, array $deferred): ToolResult {
        $tools = [];
        $missing = [];
        foreach (array_slice(array_values(array_unique(self::strings($args['names'] ?? []))), 0, self::MAX_NAMES) as $name) {
            $definition = $deferred[$name] ?? null;
            if ($definition === null) {
                $missing[] = $name;
                continue;
            }
            $tools[$name] = ['description' => $definition->description, 'parameters' => $definition->parameters === [] ? ['type' => 'object', 'properties' => new \stdClass()] : $definition->parameters];
        }
        $out = ['tools' => $tools === [] ? new \stdClass() : $tools];
        if ($missing !== []) {
            $out['not_found'] = $missing;
            $out['hint'] = t('tool.tool_describe.not_found');
        }
        return ToolResult::ok(self::json($out));
    }

    /** @return list<string> a string or a list of strings, non-empty ones */
    private static function strings(mixed $value): array {
        $list = is_string($value) ? [$value] : (is_array($value) ? $value : []);
        return array_values(array_filter(array_map(static fn(mixed $v): string => is_string($v) ? trim($v) : '', $list), static fn(string $v): bool => $v !== ''));
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
