<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use OpenCCK\App\Job\HookReceiver;
use Throwable;

/**
 * POST /hooks/{id} — a signed event for a job by event; the run starts in the background and the answer comes at once.
 *
 * spec: brief/scheduler.md#как-сделано-задания-по-событию-и-проекты — events by webhook
 */
final class JobHookHandler implements RequestHandler {
    public function __construct(private readonly HookReceiver $receiver) {}

    public function handleRequest(Request $request): Response {
        /** @var mixed $args */
        $args = $request->getAttribute(Router::class);
        $id = is_array($args) && isset($args['id']) && is_string($args['id']) && ctype_digit($args['id']) ? (int) $args['id'] : 0;
        try {
            $request->getBody()->increaseSizeLimit(HookReceiver::BODY_MAX + 1);
            $body = $request->getBody()->buffer(limit: HookReceiver::BODY_MAX + 1);
        } catch (Throwable) {
            return self::text(413, 'too large');
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        [$status, $text] = $this->receiver->receive($id, $headers, $body);
        return self::text($status, $text);
    }

    private static function text(int $status, string $text): Response {
        return new Response($status, ['content-type' => 'text/plain; charset=utf-8'], $text . "\n");
    }
}
