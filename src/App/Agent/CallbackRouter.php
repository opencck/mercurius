<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Service\OwnerDirectory;

use function Mercurius\I18n\t;

/**
 * Routes button presses by the prefix of their data ("ap:…" approvals, "pv:…" visitors, "pg:…" grants). Only the
 * owner may press: anyone else gets a notice and the card keeps waiting. Every press is answered.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты — only the owner presses; the press is always answered
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221fa:L4302-L4336 (_handle_callback_query) —
 *      routing by callback data prefix, answering even unknown presses so the client stops spinning
 */
final class CallbackRouter implements CallbackHandler {
    /**
     * @param array<string, CallbackHandler> $handlers prefix => handler
     * @param list<string> $otherOwnerIds the owner's ids in other channels (`p<id>` of the WebSocket API)
     */
    public function __construct(
        private readonly Channel $channel,
        /** the owner: their account, asked at each press — ownership may pass on */
        private readonly OwnerDirectory|string $ownerUserId,
        private readonly array $handlers,
        private readonly array $otherOwnerIds = [],
        /** @var list<string> prefixes whose handlers take presses of participants too and check the right themselves */
        private readonly array $openPrefixes = [],
    ) {}

    private function byOwner(string $userId): bool {
        if ($userId === OwnerDirectory::chat($this->ownerUserId)) {
            return true;
        }
        // the owner's id in the WebSocket API follows the owner too
        $owner = $this->ownerUserId instanceof OwnerDirectory ? $this->ownerUserId->principal() : null;
        return $owner !== null ? $userId === 'p' . $owner->id : in_array($userId, $this->otherOwnerIds, true);
    }

    public function handleCallback(IncomingCallback $callback): void {
        $open = in_array(explode(':', $callback->data, 2)[0], $this->openPrefixes, true);
        $prefix = explode(':', $callback->data, 2)[0];
        $handler = $this->handlers[$prefix] ?? null;
        if (!$open && !$this->byOwner($callback->userId) && !($handler instanceof TakesOwnPresses && $handler->ownPress($callback))) {
            $this->channel->answerCallback($callback->callbackId, t('agent.callback_router.eto_mozhet_tolko_vladelets'), true);
            return;
        }
        if ($handler === null) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $handler->handleCallback($callback);
    }
}
