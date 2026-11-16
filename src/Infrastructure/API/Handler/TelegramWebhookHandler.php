<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API\Handler;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use Mercurius\Messenger\Telegram\WebhookRequestValidator;

/**
 * POST /telegram/webhook — checks X-Telegram-Bot-Api-Secret-Token, queues the update and answers at once;
 * the message is handled asynchronously, in order within its chat.
 */
final class TelegramWebhookHandler implements RequestHandler {
    private const MAX_BODY = 1_048_576;

    public function __construct(private readonly WebhookProcessor $processor) {}

    public function handleRequest(Request $request): Response {
        $request->getBody()->increaseSizeLimit(self::MAX_BODY);
        $body = $request->getBody()->buffer();
        $accepted = $this->processor->process($request->getHeader(WebhookRequestValidator::SECRET_HEADER), $body);

        return new Response($accepted ? HttpStatus::OK : HttpStatus::FORBIDDEN, ['content-type' => 'text/plain; charset=utf-8'], $accepted ? 'ok' : 'forbidden');
    }
}
