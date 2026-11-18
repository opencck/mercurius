<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;

use function Mercurius\I18n\t;

/**
 * Routes button presses by the prefix of their data ("ap:…" approvals, "pv:…" visitors, "pg:…" grants). Only the
 * owner may press: anyone else gets a notice and the card keeps waiting. Every press is answered.
 *
 * spec: BRIEF.md#подтверждения-и-запреты — only the owner presses; the press is always answered
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221f:L4302-L4336 (_handle_callback_query) —
 *      routing by callback data prefix, answering even unknown presses so the client stops spinning
 */
final class CallbackRouter implements CallbackHandler {
    /**
     * @param array<string, CallbackHandler> $handlers prefix => handler
     * @param list<string> $otherOwnerIds the owner's ids in other channels (`p<id>` of the WebSocket API)
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly string $ownerUserId,
        private readonly array $handlers,
        private readonly array $otherOwnerIds = [],
    ) {}

    public function handleCallback(IncomingCallback $callback): void {
        if ($callback->userId !== $this->ownerUserId && !in_array($callback->userId, $this->otherOwnerIds, true)) {
            $this->channel->answerCallback($callback->callbackId, t('agent.callback_router.eto_mozhet_tolko_vladelets'), true);
            return;
        }
        $prefix = explode(':', $callback->data, 2)[0];
        $handler = $this->handlers[$prefix] ?? null;
        if ($handler === null) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $handler->handleCallback($callback);
    }
}
