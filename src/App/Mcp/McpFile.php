<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use InvalidArgumentException;
use JsonException;
use OpenCCK\Domain\Mcp\McpServer;

use function Mercurius\I18n\t;

/**
 * A context's .mcp.json: `{"mcpServers": {"<name>": {...}}}`. A bad entry is named and left out; the others stay. A
 * file that is not such an object at all (cut in the middle of a write, emptied, not JSON) is broken: it says nothing
 * of its entries, so nothing is taken out because of it.
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
     * @param array<string, string> $bad entries with a proper name that could not be read, and why
     * @param array<array-key, mixed> $servers the entries as the file holds them, bad ones too: a command that changes
     *        one entry writes the others back as they were
     */
    private function __construct(
        public readonly array $entries,
        public readonly array $errors,
        public readonly bool $broken = false,
        public readonly array $bad = [],
        public readonly array $servers = [],
    ) {}

    public static function parse(string $text): self {
        if (trim($text) === '') {
            return new self([], [t('mcp.file.no_servers')], broken: true);
        }
        if (strlen($text) > self::MAX_BYTES) {
            return new self([], [t('mcp.file.too_big')], broken: true);
        }
        try {
            /** @psalm-suppress MixedAssignment the file is any JSON; its shape is checked just below */
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new self([], [sprintf(t('mcp.file.not_json'), $e->getMessage())], broken: true);
        }
        /** @psalm-suppress MixedAssignment the list is any JSON value; it is checked just below */
        $servers = is_array($data) ? ($data['mcpServers'] ?? null) : null;
        if (!is_array($servers)) {
            return new self([], [t('mcp.file.no_servers')], broken: true);
        }
        $entries = [];
        $errors = [];
        $bad = [];
        /** @psalm-suppress MixedAssignment an entry is any JSON value; McpEntry checks it */
        foreach ($servers as $name => $entry) {
            try {
                $entries[(string) $name] = McpEntry::fromArray((string) $name, is_array($entry) ? $entry : []);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                if (preg_match(McpServer::NAME_PATTERN, (string) $name) === 1) {
                    $bad[(string) $name] = $e->getMessage();
                }
            }
        }
        return new self($entries, $errors, false, $bad, $servers);
    }

    /** @param array<array-key, mixed> $entries entries, or values of the file kept as they were */
    public static function render(array $entries): string {
        $servers = [];
        /** @psalm-suppress MixedAssignment a value kept as it was is written back as it was */
        foreach ($entries as $name => $entry) {
            $servers[$name] = $entry instanceof McpEntry ? $entry->toArray() : $entry;
        }
        return json_encode(['mcpServers' => (object) $servers], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
