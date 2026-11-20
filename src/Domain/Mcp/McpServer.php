<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Mcp;

/**
 * An external MCP server over HTTP, added and approved by the owner.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие
 */
final class McpServer implements \Mercurius\Mcp\McpEndpoint {
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
        /** names or masks of the tools to register, comma-separated; null — all; '' — none */
        public readonly ?string $include = null,
        /** names or masks of the tools not to register; the whitelist wins */
        public readonly string $exclude = '',
    ) {}

    /**
     * Whether a tool of this server goes into the registry.
     *
     * ref: references/hermes-agent/tools/mcp_tool_registration.py@e96221fa:L251-L264 (_make_tool_filter) — include is
     *      a whitelist (empty registers nothing), exclude a blacklist, exact names or globs, include wins
     */
    public function registers(string $tool): bool {
        if ($this->include !== null) {
            return self::matches($tool, $this->include);
        }
        return !self::matches($tool, $this->exclude);
    }

    private static function matches(string $tool, string $masks): bool {
        foreach (explode(',', $masks) as $mask) {
            $mask = trim($mask);
            if ($mask !== '' && fnmatch($mask, $tool)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The same server with other headers: the account of one request.
     *
     * @param array<non-empty-string, string> $headers
     */
    public function withHeaders(#[\SensitiveParameter] array $headers): self {
        return new self($this->id, $this->name, $this->url, $this->secret, $this->status, $this->toolsHash, $this->note, $headers, $this->include, $this->exclude);
    }

    public function endpointName(): string {
        return $this->name;
    }

    public function endpointUrl(): string {
        return $this->url;
    }

    public function endpointHeaders(): array {
        return $this->headers;
    }
}
