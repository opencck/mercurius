<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

/**
 * Sources of an MCP brought into the sandbox: their revision and what the agent reads to make a plan (untrusted).
 *
 * spec: brief/skills-mcp.md#установка-и-обновление
 */
final class McpFetched {
    public function __construct(
        public readonly string $revision,
        public readonly string $overview,
    ) {}
}
