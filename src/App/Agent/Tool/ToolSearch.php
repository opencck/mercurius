<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Closure;
use Mercurius\Llm\ToolDefinition;

use function Mercurius\I18n\t;

/**
 * Tools of MCP servers over a threshold do not go to the model whole: it gets their names (or only their number) in
 * the description of `tool_search`, and three bridge tools — find, read the schema, call. The core tools stay as
 * they are.
 *
 * spec: BRIEF.md#скиллы-и-mcp — the search of MCP tools
 * ref: references/hermes-agent/tools/tool_search.py@e96221fa:L197-L224 (estimate_tokens_from_schemas,
 *      listing_token_budget) — chars / 4, a listing of at most min(4000, 5% of the window) tokens; hermes turns the
 *      bridge on whenever a deferrable tool exists, here only when the schemas of MCP tools are over 5% of the window,
 *      so a few small servers cost nothing new
 * ref: references/hermes-agent/tools/tool_search.py@e96221fa:L241-L332 (_search_description, bridge_tool_schemas) —
 *      the three bridge tools and the listing in the description of tool_search; tool_call here takes one call, as
 *      hermes does for local tools (L599-L600)
 * ref: references/hermes-agent/tools/tool_search_catalog.py@e96221fa:L213-L229, L265-L330 (_short_desc,
 *      build_catalog_listing_with_form) — a line is the first sentence up to 80 characters; full lines, then names,
 *      then the number only. hermes collapses per server; MCP names here do not say where a server name ends, so the
 *      whole listing goes down a form at once
 * ref: references/hermes-agent/tools/tool_search_catalog.py@e96221fa:L176-L208 (search_catalog) — an exact name first,
 *      then tools that carry every word of the query some tool has; hermes ranks by BM25, here by the rarity of the
 *      words matched, which is enough for tens of tools
 */
final class ToolSearch {
    public const SEARCH = ToolSearchTool::SEARCH;
    public const DESCRIBE = ToolSearchTool::DESCRIBE;
    public const CALL = 'tool_call';
    public const BRIDGE = [self::SEARCH, self::DESCRIBE, self::CALL];
    private const CHARS_PER_TOKEN = 4;
    private const LINE_CHARS = 80;

    /** @param Closure(string): int $windowOf the window of a model, in tokens */
    public function __construct(
        private readonly Closure $windowOf,
        private readonly float $thresholdPct = 5.0,
        private readonly int $listingMaxTokens = 4000,
    ) {}

    /** A tool that may go behind the bridge: one of an MCP server (mcp_<server>_<tool>), not the core mcp_resources. */
    public static function deferrable(string $name): bool {
        return str_starts_with($name, 'mcp_') && $name !== Skills\McpResourcesTool::NAME;
    }

    /**
     * The tools for one request to $model: as they are, or with the MCP tools behind the bridge.
     *
     * @param list<ToolDefinition> $defs
     * @return list<ToolDefinition>
     */
    public function assemble(array $defs, string $model): array {
        $defs = array_values(array_filter($defs, static fn(ToolDefinition $d): bool => !in_array($d->name, self::BRIDGE, true)));
        $deferred = array_values(array_filter($defs, static fn(ToolDefinition $d): bool => self::deferrable($d->name)));
        $threshold = (int) ((float) max(1, ($this->windowOf)($model)) * $this->thresholdPct / 100.0);
        if ($deferred === [] || self::tokens($deferred) <= $threshold) {
            return $defs;
        }
        $visible = array_values(array_filter($defs, static fn(ToolDefinition $d): bool => !self::deferrable($d->name)));
        return [...$visible, ...self::bridge(count($deferred), $this->listing($deferred, min($this->listingMaxTokens, $threshold)))];
    }

    /**
     * Tools whose name is the query, else those that carry each of its words some tool has, rarer words first.
     *
     * @param list<ToolDefinition> $defs
     * @return list<ToolDefinition>
     */
    public static function search(array $defs, string $query, int $limit): array {
        $words = self::words($query);
        if ($words === [] || $limit < 1) {
            return [];
        }
        $exact = mb_strtolower(trim($query));
        $docs = [];
        $frequency = [];
        foreach ($defs as $i => $def) {
            $docs[$i] = array_fill_keys(self::words($def->name . ' ' . $def->description), true);
            foreach (array_keys($docs[$i]) as $word) {
                $frequency[$word] = ($frequency[$word] ?? 0) + 1;
            }
        }
        $answerable = array_values(array_filter($words, static fn(string $w): bool => isset($frequency[$w])));
        $scored = [];
        foreach ($defs as $i => $def) {
            if (mb_strtolower($def->name) === $exact) {
                $scored[] = [INF, $i];
                continue;
            }
            if ($answerable === []) {
                continue;
            }
            $score = 0.0;
            foreach ($answerable as $word) {
                if (!isset($docs[$i][$word])) {
                    continue 2;
                }
                $score += log(1.0 + (float) count($defs) / (float) $frequency[$word]);
            }
            $scored[] = [$score, $i];
        }
        usort($scored, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        return array_map(static fn(array $s): ToolDefinition => $defs[$s[1]], array_slice($scored, 0, $limit));
    }

    /**
     * The tool and the arguments a `tool_call` names, or why it names none.
     *
     * @param array<array-key, mixed> $args
     * @return array{name: string, arguments: array<string, mixed>}|string
     */
    public static function unwrap(array $args): array|string {
        $name = $args['name'] ?? null;
        /** @var mixed $arguments */
        $arguments = $args['arguments'] ?? [];
        if (is_string($arguments)) {
            /** @var mixed $arguments */
            $arguments = json_decode($arguments, true);
        }
        if (!is_string($name) || $name === '' || !is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return t('tool.tool_call.bad');
        }
        if (in_array($name, self::BRIDGE, true)) {
            return sprintf(t('tool.tool_call.nested'), $name);
        }
        /** @var array<string, mixed> $arguments */
        return ['name' => $name, 'arguments' => $arguments];
    }

    /**
     * The bridge as the model sees it; the listing goes into the description of `tool_search`.
     *
     * @return list<ToolDefinition>
     */
    public static function bridge(int $count, string $listing = ''): array {
        return [
            new ToolDefinition(self::SEARCH, sprintf(t('tool.tool_search.about'), $count) . ($listing === '' ? '' : "\n\n" . $listing), [
                'type' => 'object',
                'properties' => [
                    'queries' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => ToolSearchTool::MAX_QUERIES, 'description' => t('tool.tool_search.queries')],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => t('tool.tool_search.limit')],
                ],
                'required' => ['queries'],
            ]),
            new ToolDefinition(self::DESCRIBE, t('tool.tool_describe.about'), [
                'type' => 'object',
                'properties' => [
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => ToolSearchTool::MAX_NAMES, 'description' => t('tool.tool_describe.names')],
                ],
                'required' => ['names'],
            ]),
            new ToolDefinition(self::CALL, t('tool.tool_call.about'), [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => t('tool.tool_call.name')],
                    'arguments' => ['type' => 'object', 'description' => t('tool.tool_call.arguments')],
                ],
                'required' => ['name', 'arguments'],
            ]),
        ];
    }

    /** @param list<ToolDefinition> $defs */
    private static function tokens(array $defs): int {
        $chars = 0;
        foreach ($defs as $def) {
            $chars += mb_strlen($def->name . $def->description) + mb_strlen((string) json_encode($def->parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }

    /** @param list<ToolDefinition> $deferred */
    private function listing(array $deferred, int $maxTokens): string {
        usort($deferred, static fn(ToolDefinition $a, ToolDefinition $b): int => strcmp($a->name, $b->name));
        $full = [t('tool.tool_search.listing')];
        $names = [];
        foreach ($deferred as $def) {
            $line = self::firstSentence($def->description);
            $full[] = '- ' . $def->name . ($line === '' ? '' : ': ' . $line);
            $names[] = $def->name;
        }
        foreach ([implode("\n", $full), t('tool.tool_search.listing') . "\n" . implode(', ', $names)] as $text) {
            if (mb_strlen($text) / self::CHARS_PER_TOKEN <= $maxTokens) {
                return $text;
            }
        }
        return sprintf(t('tool.tool_search.unlisted'), count($deferred));
    }

    /** The first sentence of a description, at most 80 characters, cut on a word. */
    private static function firstSentence(string $description): string {
        $text = trim(preg_replace('~\s+~u', ' ', $description) ?? '');
        if (preg_match('~^.*?(?<!\be\.g)(?<!\bi\.e)(?<!\betc)[.!?](?=\s|$)~u', $text, $m) === 1) {
            $text = $m[0];
        }
        if (mb_strlen($text) <= self::LINE_CHARS) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::LINE_CHARS);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ',;: ') . '…';
    }

    /** @return list<string> lower-case words of a text; a name splits on _ and - too */
    private static function words(string $text): array {
        preg_match_all('~[\p{L}\p{N}]+~u', mb_strtolower($text), $m);
        return array_values(array_unique(array_filter($m[0], static fn(string $w): bool => mb_strlen($w) > 1 || ctype_digit($w))));
    }
}
