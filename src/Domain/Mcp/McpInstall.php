<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Mcp;

/**
 * An installed MCP server: code in the sandbox under a Unix user of its own, a pinned revision and the one before it
 * for a rollback, the approved set of tools with their danger classes, and the entry its installer approved.
 *
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение
 */
final class McpInstall {
    /** the sandbox holder of an installed MCP is this plus its number: a user of its own, apart from people and processes */
    public const HOLDER_BASE = 800_000;
    /** the name of the install is the name of its entry in .mcp.json */
    public const NAME_PATTERN = McpServer::NAME_PATTERN;

    /**
     * @param array<string, string> $classes tool => read|write|irreversible, as the approver set them
     * @param array<array-key, mixed>|null $entry the entry of .mcp.json the install approved
     * @param list<string> $steps the steps of the approved plan
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $source,
        public readonly int $installedBy,
        public readonly ?string $revision = null,
        public readonly ?string $previous = null,
        public readonly ?string $toolsHash = null,
        public readonly array $classes = [],
        public readonly ?array $entry = null,
        public readonly ?string $latest = null,
        public readonly array $steps = [],
    ) {}

    public function holder(): int {
        return self::HOLDER_BASE + $this->id;
    }

    /** Where the code lies for every process: the link the owners' area keeps to the install's home. */
    public function path(): string {
        return '/workspace/mcp/' . $this->name;
    }
}
