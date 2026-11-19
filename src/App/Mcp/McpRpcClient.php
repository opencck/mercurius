<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\Cancellation;
use OpenCCK\Domain\Mcp\McpServer;
use RuntimeException;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * The MCP methods over any JSON-RPC transport: the list of tools (by pages), a call, resources and reading one. A
 * transport gives a session and one request in it; HTTP and stdio differ only there.
 *
 * spec: BRIEF.md#скиллы-и-mcp — one client for HTTP and stdio servers
 */
abstract class McpRpcClient implements McpClient {
    protected const PROTOCOL = '2025-06-18';
    private const PAGES = 10;

    /** @throws RuntimeException */
    abstract protected function session(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): object|string|null;

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed> the result of the request
     * @throws RuntimeException the server answered with an error or did not answer
     */
    abstract protected function rpc(McpServer $server, #[SensitiveParameter] ?string $token, object|string|null $session, string $method, array $params, Cancellation $cancellation): array;

    public function tools(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array {
        $tools = [];
        foreach ($this->pages($server, $token, 'tools/list', 'tools', $cancellation) as $tool) {
            if (!isset($tool['name']) || !is_string($tool['name'])) {
                continue;
            }
            $tools[] = [
                'name' => $tool['name'],
                'description' => isset($tool['description']) && is_string($tool['description']) ? $tool['description'] : '',
                'schema' => isset($tool['inputSchema']) && is_array($tool['inputSchema']) ? $tool['inputSchema'] : ['type' => 'object'],
                'annotations' => isset($tool['annotations']) && is_array($tool['annotations']) ? $tool['annotations'] : [],
            ];
        }
        return $tools;
    }

    public function call(McpServer $server, #[SensitiveParameter] ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
        $session = $this->session($server, $token, $cancellation);
        $result = $this->rpc($server, $token, $session, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $cancellation);
        return self::content($result);
    }

    /**
     * The text of a tool result, whether it is an error, and the files in it: an embedded resource with a blob, an
     * image or audio with data. A binary part is not text for the model; the caller keeps it as a file.
     *
     * spec: BRIEF.md#интернет-и-браузер — an attachment of a letter reaches the sandbox
     *
     * @param array<array-key, mixed> $result
     * @return array{0: string, 1: bool, 2: list<array{name: string, type: string, bytes: string}>}
     */
    public static function content(array $result): array {
        $texts = [];
        $files = [];
        $content = isset($result['content']) && is_array($result['content']) ? $result['content'] : [];
        foreach (array_keys($content) as $k) {
            $part = $content[$k] ?? null;
            if (!is_array($part)) {
                continue;
            }
            $type = isset($part['type']) && is_string($part['type']) ? $part['type'] : '';
            $file = self::file($part, count($files) + 1);
            if ($file !== null) {
                $files[] = $file;
                continue;
            }
            $resource = $type === 'resource' && isset($part['resource']) && is_array($part['resource']) ? $part['resource'] : null;
            $texts[] = match (true) {
                $type === 'text' && isset($part['text']) && is_string($part['text']) => $part['text'],
                $resource !== null && isset($resource['text']) && is_string($resource['text']) => $resource['text'],
                default => sprintf('(%s)', $type === '' ? t('mcp.http_mcp_client.neizvestnoe_soderzhimoe') : $type),
            };
        }
        if (isset($result['structuredContent']) && $texts === []) {
            $json = json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $texts[] = $json === false ? '' : $json;
        }
        return [implode("\n", $texts), ($result['isError'] ?? false) === true, $files];
    }

    /**
     * @param array<array-key, mixed> $part
     * @return array{name: string, type: string, bytes: string}|null
     */
    private static function file(array $part, int $number): ?array {
        $type = isset($part['type']) && is_string($part['type']) ? $part['type'] : '';
        if ($type === 'resource' && isset($part['resource']) && is_array($part['resource'])) {
            $resource = $part['resource'];
            /** @var mixed $data */
            $data = $resource['blob'] ?? null;
            $uri = isset($resource['uri']) && is_string($resource['uri']) ? $resource['uri'] : '';
            $mime = isset($resource['mimeType']) && is_string($resource['mimeType']) ? $resource['mimeType'] : 'application/octet-stream';
            $path = parse_url($uri, PHP_URL_PATH);
            $name = rawurldecode(basename(is_string($path) ? $path : ''));
        } elseif ($type === 'image' || $type === 'audio') {
            /** @var mixed $data */
            $data = $part['data'] ?? null;
            $mime = isset($part['mimeType']) && is_string($part['mimeType']) ? $part['mimeType'] : 'application/octet-stream';
            $name = sprintf('%s-%d.%s', $type, $number, explode('/', $mime)[1] ?? 'bin');
        } else {
            return null;
        }
        $bytes = is_string($data) ? base64_decode($data, true) : false;
        if ($bytes === false) {
            return null;
        }
        $name = preg_replace('~[^\p{L}\p{N}._ -]~u', '_', $name) ?? '';
        return ['name' => trim($name, ' .') === '' ? sprintf('file-%d', $number) : $name, 'type' => $mime, 'bytes' => $bytes];
    }

    /**
     * ref: references/hermes-agent/tools/mcp_tool_handlers.py@e96221fa:L970-L996 (_render_resource_list,
     *      _render_read_resource) — uri, name, description, mimeType of each resource; text parts joined, binary ones named
     */
    public function resources(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array {
        $resources = [];
        foreach ($this->pages($server, $token, 'resources/list', 'resources', $cancellation) as $item) {
            if (!isset($item['uri']) || !is_string($item['uri'])) {
                continue;
            }
            $resources[] = [
                'uri' => $item['uri'],
                'name' => isset($item['name']) && is_string($item['name']) ? $item['name'] : '',
                'description' => isset($item['description']) && is_string($item['description']) ? $item['description'] : '',
                'mimeType' => isset($item['mimeType']) && is_string($item['mimeType']) ? $item['mimeType'] : '',
            ];
        }
        return $resources;
    }

    public function readResource(McpServer $server, #[SensitiveParameter] ?string $token, string $uri, Cancellation $cancellation): string {
        $session = $this->session($server, $token, $cancellation);
        $result = $this->rpc($server, $token, $session, 'resources/read', ['uri' => $uri], $cancellation);
        $parts = [];
        $contents = isset($result['contents']) && is_array($result['contents']) ? $result['contents'] : [];
        foreach (array_keys($contents) as $k) {
            $part = $contents[$k] ?? null;
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            } elseif (isset($part['blob']) && is_string($part['blob'])) {
                $parts[] = sprintf(t('mcp.http_mcp_client.binary'), (int) (strlen($part['blob']) * 3 / 4));
            }
        }
        return implode("\n", $parts);
    }

    /**
     * The parameters of the request that starts a session.
     *
     * @return array<string, mixed>
     */
    protected static function initializeParams(): array {
        return ['protocolVersion' => self::PROTOCOL, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'mercurius', 'version' => '1']];
    }

    /** @return list<array<array-key, mixed>> the items of every page of a paginated list */
    private function pages(McpServer $server, ?string $token, string $method, string $key, Cancellation $cancellation): array {
        $session = $this->session($server, $token, $cancellation);
        $items = [];
        $cursor = null;
        for ($page = 0; $page < self::PAGES; $page++) {
            $result = $this->rpc($server, $token, $session, $method, $cursor === null ? [] : ['cursor' => $cursor], $cancellation);
            $list = isset($result[$key]) && is_array($result[$key]) ? $result[$key] : [];
            foreach (array_keys($list) as $k) {
                /** @var mixed $item */
                $item = $list[$k] ?? null;
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $cursor = isset($result['nextCursor']) && is_string($result['nextCursor']) ? $result['nextCursor'] : null;
            if ($cursor === null) {
                break;
            }
        }
        return $items;
    }
}
