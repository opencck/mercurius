<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\MemberRepository;

use function Mercurius\I18n\t;

/**
 * /context in a private chat: without an argument the place of the conversation and buttons to switch it, with one —
 * the switch at once. A button is pressed only by the member of that private chat.
 *
 * spec: brief/participants.md#контекст-беседы-в-личном-чате
 */
final class ContextCommands implements CallbackHandler {
    public const PREFIX = 'cx';

    public function __construct(
        private readonly ConversationPlaces $places,
        private readonly Channel $channel,
        private readonly MemberRepository $principals,
    ) {}

    /** The answer to /context; null — the place went as a message with buttons. */
    public function command(Member $who, string $channel, string $chatId, string $argument): ?string {
        if (!ConversationPlaces::isPrivate($chatId)) {
            return t('place.private_only');
        }
        if (trim($argument) !== '') {
            return $this->places->switchTo($who, $channel, $chatId, $argument)[1];
        }
        $row = [new Button(t('place.button_personal'), self::PREFIX . ':p')];
        if ($who->isOwner()) {
            $row[] = new Button(t('place.button_global'), self::PREFIX . ':g');
        }
        $buttons = [$row];
        foreach (array_chunk($this->places->choices($who), 2) as $pair) {
            $buttons[] = array_map(static fn(\OpenCCK\Domain\Project\Project $p): Button => new Button('📁 ' . $p->name, self::PREFIX . ':' . $p->id), $pair);
        }
        $this->channel->send(new OutgoingMessage($chatId, $this->now($who, $channel, $chatId) . "\n\n" . t('place.usage'), buttons: $buttons));
        return null;
    }

    public function handleCallback(IncomingCallback $callback): void {
        $who = $this->principals->findByIdentity($callback->channel, $callback->userId);
        if (preg_match('/^' . self::PREFIX . ':(p|g|\d+)$/', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        // a private chat is the member's own: its id is theirs
        if ($who === null || $callback->userId !== $callback->chatId) {
            $this->channel->answerCallback($callback->callbackId, t('place.not_yours'));
            return;
        }
        $target = match ($m[1]) {
            'p' => 'personal',
            'g' => 'global',
            default => $m[1],
        };
        [, $text] = $this->places->switchTo($who, $callback->channel, $callback->chatId, $target);
        $this->channel->answerCallback($callback->callbackId, $text);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }

    private function now(Member $who, string $channel, string $chatId): string {
        return sprintf(t('place.now'), $this->places->of($who, $channel, $chatId)->title());
    }
}
