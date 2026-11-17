<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Mcp;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use OpenCCK\App\Mcp\McpClient;
use OpenCCK\Domain\Mcp\McpServer;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * MCP over Streamable HTTP: JSON-RPC 2.0 by POST, the answer as JSON or as a server-sent event. A session per use:
 * initialize, then the request.
 *
 * spec: BRIEF.md#скиллы-и-mcp — the MCP client for HTTP servers
 */
final class HttpMcpClient implements McpClient {
    private const PROTOCOL = '2025-06-18';
    private const PAGES = 10;

    public function __construct(private readonly HttpClient $http) {}

    public function tools(McpServer $server, #[SensitiveParameter] ?string $token, Cancellation $cancellation): array {
        $session = $this->initialize($server, $token, $cancellation);
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < self::PAGES; $page++) {
            $result = $this->rpc($server, $token, $session, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor], $cancellation);
            $list = isset($result['tools']) && is_array($result['tools']) ? $result['tools'] : [];
            foreach (array_keys($list) as $k) {
                $tool = $list[$k] ?? null;
                if (!is_array($tool) || !isset($tool['name']) || !is_string($tool['name'])) {
                    continue;
                }
                $tools[] = [
                    'name' => $tool['name'],
                    'description' => isset($tool['description']) && is_string($tool['description']) ? $tool['description'] : '',
                    'schema' => isset($tool['inputSchema']) && is_array($tool['inputSchema']) ? $tool['inputSchema'] : ['type' => 'object'],
                    'annotations' => isset($tool['annotations']) && is_array($tool['annotations']) ? $tool['annotations'] : [],
                ];
            }
            $cursor = isset($result['nextCursor']) && is_string($result['nextCursor']) ? $result['nextCursor'] : null;
            if ($cursor === null) {
                break;
            }
        }
        return $tools;
    }

    public function call(McpServer $server, #[SensitiveParameter] ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
        $session = $this->initialize($server, $token, $cancellation);
        $result = $this->rpc($server, $token, $session, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $cancellation);
        $texts = [];
        $content = isset($result['content']) && is_array($result['content']) ? $result['content'] : [];
        foreach (array_keys($content) as $k) {
            $part = $content[$k] ?? null;
            if (!is_array($part)) {
                continue;
            }
            $type = isset($part['type']) && is_string($part['type']) ? $part['type'] : '';
            $texts[] = $type === 'text' && isset($part['text']) && is_string($part['text'])
                ? $part['text']
                : sprintf('(%s)', $type === '' ? 'неизвестное содержимое' : $type);
        }
        if (isset($result['structuredContent']) && $texts === []) {
            $json = json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $texts[] = $json === false ? '' : $json;
        }
        return [implode("\n", $texts), ($result['isError'] ?? false) === true];
    }

    private function initialize(McpServer $server, ?string $token, Cancellation $cancellation): ?string {
        [, $session] = $this->send($server, $token, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'mercurius', 'version' => '1']],
        ], $cancellation);
        $this->send($server, $token, $session, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $cancellation);
        return $session;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function rpc(McpServer $server, ?string $token, ?string $session, string $method, array $params, Cancellation $cancellation): array {
        [$message] = $this->send($server, $token, $session, ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => (object) $params], $cancellation);
        if (isset($message['error']) && is_array($message['error'])) {
            $text = isset($message['error']['message']) && is_string($message['error']['message']) ? $message['error']['message'] : 'ошибка';
            throw new RuntimeException('MCP-сервер ответил ошибкой: ' . mb_substr($text, 0, 300));
        }
        return isset($message['result']) && is_array($message['result']) ? $message['result'] : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{array<array-key, mixed>, ?string} the JSON-RPC message answering this one, and the session id
     */
    private function send(McpServer $server, ?string $token, ?string $session, array $body, Cancellation $cancellation): array {
        $request = new Request($server->url, 'POST', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $request->setHeader('content-type', 'application/json');
        $request->setHeader('accept', 'application/json, text/event-stream');
        $request->setHeader('mcp-protocol-version', self::PROTOCOL);
        if ($token !== null) {
            $request->setHeader('authorization', 'Bearer ' . $token);
        }
        if ($session !== null) {
            $request->setHeader('mcp-session-id', $session);
        }
        $request->setTransferTimeout(60);
        $request->setInactivityTimeout(60);
        $request->setBodySizeLimit(4_194_304);
        try {
            $response = $this->http->request($request, $cancellation);
            $text = $response->getBody()->buffer($cancellation);
        } catch (Throwable $e) {
            throw new RuntimeException('MCP-сервер не ответил: ' . (new \ReflectionClass($e))->getShortName());
        }
        if ($response->getStatus() >= 400) {
            throw new RuntimeException(sprintf('MCP-сервер ответил HTTP %d', $response->getStatus()));
        }
        $session = $response->getHeader('mcp-session-id') ?? $session;
        if (!isset($body['id'])) {
            return [[], $session];
        }
        foreach (self::messages($text, $response->getHeader('content-type') ?? '') as $message) {
            if (($message['id'] ?? null) === $body['id']) {
                return [$message, $session];
            }
        }
        throw new RuntimeException('MCP-сервер не прислал ответ на запрос');
    }

    /** @return list<array<array-key, mixed>> JSON-RPC messages of a JSON body or of an event stream */
    public static function messages(string $text, string $contentType): array {
        $chunks = [$text];
        if (str_contains($contentType, 'text/event-stream')) {
            $chunks = [];
            foreach (preg_split('~\r?\n~', $text) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $chunks[] = substr($line, 5);
                }
            }
        }
        $messages = [];
        foreach ($chunks as $chunk) {
            /** @var mixed $decoded */
            $decoded = json_decode(trim($chunk), true, 64);
            if (is_array($decoded)) {
                if (array_is_list($decoded)) {
                    foreach (array_keys($decoded) as $k) {
                        if (isset($decoded[$k]) && is_array($decoded[$k])) {
                            $messages[] = $decoded[$k];
                        }
                    }
                } else {
                    $messages[] = $decoded;
                }
            }
        }
        return $messages;
    }
}
