<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use OpenCCK\App\Peer\A2aFiles;
use OpenCCK\App\Peer\A2aServer;
use Throwable;

/**
 * POST /a2a/files — the files of a message ahead of it (multipart/form-data, up to 20 MB, read with a limit and cut off
 * past it); GET /a2a/files/<number> — a file of an answer. Both are signed by the agent's key as POST /a2a, and so are
 * the answers. There is an entrance for files with either A2A transport.
 *
 * spec: brief/internet.md#между-агентами
 */
final class A2aFilesHandler implements RequestHandler {
    public function __construct(private readonly A2aServer $server) {}

    public function handleRequest(Request $request): Response {
        $body = '';
        if ($request->getMethod() === 'POST') {
            try {
                $request->getBody()->increaseSizeLimit(A2aFiles::BODY_MAX);
                $body = $request->getBody()->buffer(limit: A2aFiles::BODY_MAX);
            } catch (Throwable) {
                return self::json(413, ['error' => 'payload too large'], []);
            }
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        [$agent, $status, $why] = $this->server->authenticate($headers, $request->getMethod(), $request->getUri()->getPath(), $body);
        if ($agent === null) {
            return self::json($status, ['error' => $why], []);
        }
        $nonce = $headers['x-a2a-nonce'] ?? '';
        if ($request->getMethod() === 'POST') {
            $answer = $this->server->upload($agent, $headers['content-type'] ?? '', $body);
            $json = json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return new Response(isset($answer['error']) ? 400 : 200, ['content-type' => 'application/json'] + $this->server->sign($nonce, $json), $json);
        }
        /** @var mixed $args */
        $args = $request->hasAttribute(Router::class) ? $request->getAttribute(Router::class) : [];
        /** @var mixed $given */
        $given = is_array($args) ? ($args['id'] ?? null) : null;
        $id = is_string($given) ? $given : '';
        $file = $id !== '' ? $this->server->download($agent, $id) : null;
        if ($file === null) {
            $json = '{"error":"file not found"}';
            return new Response(404, ['content-type' => 'application/json'] + $this->server->sign($nonce, $json), $json);
        }
        return new Response(200, [
            'content-type' => $file['mediaType'],
            'content-disposition' => 'attachment; filename*=UTF-8\'\'' . rawurlencode($file['name']),
        ] + $this->server->sign($nonce, $file['bytes']), $file['bytes']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<non-empty-string, string> $extra
     */
    private static function json(int $status, array $data, array $extra): Response {
        return new Response($status, ['content-type' => 'application/json'] + $extra, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
