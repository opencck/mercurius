<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpenCCK\App\Peer\A2aServer;
use Throwable;

/**
 * POST /a2a — JSON-RPC of A2A v1.0 for neighbouring agents, signed both ways; GET /.well-known/agent-card.json — the
 * agent card with its public key.
 *
 * spec: BRIEF.md#сеть-агентов
 */
final class A2aHandler implements RequestHandler {
    private const BODY_MAX = 262_144;

    public function __construct(private readonly A2aServer $server) {}

    public function handleRequest(Request $request): Response {
        if ($request->getMethod() === 'GET') {
            parse_str($request->getUri()->getQuery(), $query);
            $nonce = isset($query['nonce']) && is_string($query['nonce']) ? $query['nonce'] : null;
            return self::json(200, $this->server->card($nonce), []);
        }
        try {
            $request->getBody()->increaseSizeLimit(self::BODY_MAX);
            $body = $request->getBody()->buffer(limit: self::BODY_MAX);
        } catch (Throwable) {
            return self::json(413, A2aServer::error(null, A2aServer::ERR_PARSE, 'payload too large'), []);
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        [$agent, $status, $why] = $this->server->authenticate($headers, $request->getMethod(), $request->getUri()->getPath(), $body);
        if ($agent === null) {
            return self::json($status, A2aServer::error(null, A2aServer::ERR_UNAUTHORIZED, $why), []);
        }
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        }
        [$code, $answer] = is_array($data)
            ? $this->server->handle($agent, $data)
            : [400, A2aServer::error(null, A2aServer::ERR_PARSE, 'parse error')];
        $nonce = $headers['x-a2a-nonce'] ?? '';
        $json = json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new Response($code, ['content-type' => 'application/json'] + $this->server->sign($nonce, $json), $json);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<non-empty-string, string> $extra
     */
    private static function json(int $status, array $data, array $extra): Response {
        return new Response($status, ['content-type' => 'application/json'] + $extra, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
