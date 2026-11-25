<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use InvalidArgumentException;
use JsonException;

use function Mercurius\I18n\t;

/**
 * A context's .mcp.json: `{"mcpServers": {"<name>": {...}}}`. A bad entry is named and left out; the others stay.
 *
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the format of Claude Code
 */
final class McpFile {
    public const NAME = '.mcp.json';
    /** a file bigger than this is not read: it is not a list of a few servers */
    public const MAX_BYTES = 65_536;

    /**
     * @param array<string, McpEntry> $entries
     * @param list<string> $errors
     */
    private function __construct(public readonly array $entries, public readonly array $errors) {}

    public static function parse(string $text): self {
        if (trim($text) === '') {
            return new self([], []);
        }
        if (strlen($text) > self::MAX_BYTES) {
            return new self([], [t('mcp.file.too_big')]);
        }
        try {
            /** @psalm-suppress MixedAssignment the file is any JSON; its shape is checked just below */
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new self([], [sprintf(t('mcp.file.not_json'), $e->getMessage())]);
        }
        $servers = is_array($data) ? ($data['mcpServers'] ?? []) : null;
        if (!is_array($servers)) {
            return new self([], [t('mcp.file.no_servers')]);
        }
        $entries = [];
        $errors = [];
        /** @psalm-suppress MixedAssignment an entry is any JSON value; McpEntry checks it */
        foreach ($servers as $name => $entry) {
            try {
                $entries[(string) $name] = McpEntry::fromArray((string) $name, is_array($entry) ? $entry : []);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return new self($entries, $errors);
    }

    /** @param array<string, McpEntry> $entries */
    public static function render(array $entries): string {
        $servers = [];
        foreach ($entries as $name => $entry) {
            $servers[$name] = $entry->toArray();
        }
        return json_encode(['mcpServers' => (object) $servers], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
