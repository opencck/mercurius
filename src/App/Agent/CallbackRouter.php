<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;

/**
 * Routes button presses by the prefix of their data ("ap:…" approvals, "pv:…" visitors, "pg:…" grants). Only the
 * owner may press: anyone else gets a notice and the card keeps waiting. Every press is answered.
 *
 * spec: BRIEF.md#подтверждения-и-запреты — only the owner presses; the press is always answered
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221f:L4302-L4336 (_handle_callback_query) —
 *      routing by callback data prefix, answering even unknown presses so the client stops spinning
 */
final class CallbackRouter implements CallbackHandler {
    /** @param array<string, CallbackHandler> $handlers prefix => handler */
    public function __construct(
        private readonly Channel $channel,
        private readonly string $ownerUserId,
        private readonly array $handlers,
    ) {}

    public function handleCallback(IncomingCallback $callback): void {
        if ($callback->userId !== $this->ownerUserId) {
            $this->channel->answerCallback($callback->callbackId, 'Это может только владелец.', true);
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
