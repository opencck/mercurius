<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Mcp;

/**
 * A connection to an MCP server in a context (the owners' area, a member, a project): where it leads, its token,
 * its state. A built-in server (mail) has no context: its tools are common.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — the context and the states of a connection
 */
final class McpServer implements \Mercurius\Mcp\McpEndpoint {
    public const DISABLED = 'disabled';
    public const PENDING = 'pending';
    public const CONNECTING = 'connecting';
    public const CONNECTED = 'connected';
    public const ERROR = 'error';
    public const CHANGED = 'changed';
    public const NAMES = 'names';
    public const UPDATE = 'update';
    /** a built-in server is connected: the name kept for it and for tests */
    public const ACTIVE = self::CONNECTED;
    /** the states of an approved connection that is on: its tools are registered or tried */
    public const ENABLED = [self::CONNECTING, self::CONNECTED, self::ERROR, self::UPDATE];
    /**
     * The name of an entry and of an install: lower-case letters, digits, `_` and `-`, as repositories and packages
     * of MCP servers are named. It becomes a part of a path and of tool names, where all of these are allowed.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
     */
    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,23}$~';

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
        /** owners, member:<id> or project:<id>; '' — a built-in server, common to every run */
        public readonly string $context = '',
        /** when a connection in error is tried again, unix time; null — at once or not at all */
        public readonly ?float $nextTry = null,
        /** the hash of the .mcp.json entry that was approved; null — none was */
        public readonly ?string $approvedHash = null,
        /**
         * The approved entry itself: the server is called by it, with the secrets it names put in at the call.
         *
         * @var array<array-key, mixed>|null
         */
        public readonly ?array $entry = null,
        /**
         * The environment of a local server with its secrets put in: of one start, never stored.
         *
         * @var array<string, string>
         */
        public readonly array $env = [],
        /** the hash of the entry with its secrets put in: another value is another process */
        public readonly ?string $renderedHash = null,
    ) {}

    /** The connection inside the application: its context and name; a built-in server — its name. */
    public function key(): string {
        return $this->context === '' ? $this->name : $this->context . '|' . $this->name;
    }

    /** Approved and on: its tools are registered or are being tried. */
    public function enabled(): bool {
        return in_array($this->status, self::ENABLED, true);
    }

    /** The same connection in another state. */
    public function withState(string $state, string $reason = '', ?float $nextTry = null): self {
        return new self($this->id, $this->name, $this->url, $this->secret, $state, $this->toolsHash, $reason, $this->headers, $this->include, $this->exclude, $this->context, $nextTry, $this->approvedHash, $this->entry, $this->env, $this->renderedHash);
    }

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
        return new self($this->id, $this->name, $this->url, $this->secret, $this->status, $this->toolsHash, $this->note, $headers, $this->include, $this->exclude, $this->context, $this->nextTry, $this->approvedHash, $this->entry, $this->env, $this->renderedHash);
    }

    /**
     * The same connection as one start or call sees it: the address, headers and environment of its approved entry
     * with the secrets put in.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     *
     * @param array<string, string> $headers
     * @param array<string, string> $env
     */
    public function withEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] array $env, string $hash): self {
        $sent = [];
        foreach ($headers as $header => $value) {
            if ($header !== '') {
                $sent[$header] = $value;
            }
        }
        return new self($this->id, $this->name, $url, null, $this->status, $this->toolsHash, $this->note, $sent, $this->include, $this->exclude, $this->context, $this->nextTry, $this->approvedHash, $this->entry, $env, $hash);
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
