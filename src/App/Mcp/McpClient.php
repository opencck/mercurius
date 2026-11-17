<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\Cancellation;
use OpenCCK\Domain\Mcp\McpServer;
use RuntimeException;
use SensitiveParameter;

/**
 * Talks MCP to a server: the list of its tools, a call of one, its resources and reading one of them.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 */
interface McpClient {
    /**
     * @return list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>
     * @throws RuntimeException
     */
    public function tools(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array{string, bool} the text of the result and whether the server reports an error
     * @throws RuntimeException
     */
    public function call(McpServer $server, #[SensitiveParameter] ?string $token, string $tool, array $arguments, Cancellation $cancellation): array;

    /**
     * @return list<array{uri: string, name: string, description: string, mimeType: string}>
     * @throws RuntimeException
     */
    public function resources(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array;

    /**
     * @return string the text of the resource; binary parts are named, not given
     * @throws RuntimeException
     */
    public function readResource(McpServer $server, #[SensitiveParameter] ?string $token, string $uri, Cancellation $cancellation): string;
}
