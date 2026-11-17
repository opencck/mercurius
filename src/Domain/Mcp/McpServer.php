<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Mcp;

/**
 * An external MCP server over HTTP, added and approved by the owner.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 */
final class McpServer {
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9_]{0,23}$~';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $url,
        /** the name of the vault secret with a bearer token */
        public readonly ?string $secret,
        public readonly string $status,
        public readonly ?string $toolsHash,
        public readonly string $note = '',
        /**
         * Headers sent with every request; a built-in server gets its account this way, so the model never sees it.
         *
         * @var array<non-empty-string, string>
         */
        public readonly array $headers = [],
    ) {}
}
