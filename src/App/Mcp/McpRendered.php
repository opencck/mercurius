<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

/**
 * An entry of .mcp.json with its secrets put in, for one start or one call. Never stored, never shown: the values are
 * kept here only to mask them in what the server answers. The hash tells one start from another — another value is
 * another process.
 *
 * spec: brief/skills-mcp.md#подстановка-секретов
 */
final class McpRendered {
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $env
     * @param list<string> $values the values put in
     * @param list<string> $missing names no layer had a value for
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $address,
        #[\SensitiveParameter] public readonly array $headers,
        #[\SensitiveParameter] public readonly array $env,
        #[\SensitiveParameter] public readonly array $values,
        public readonly array $missing,
        public readonly string $hash,
    ) {}
}
