<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DateTimeImmutable;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\MessageRelayRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Mercurius\I18n\t;

/**
 * Brings the answer to a personal message back: when someone the agent wrote to on a request answers in their private
 * chat, the answer becomes a message of the conversation that asked, from the one who asked, wrapped as outside data.
 * The answerer gets a short note that it was passed on.
 *
 * spec: BRIEF.md#личные-сообщения-участникам
 */
final class RelayService {
    /** How long a question waits for its answer. */
    private const WAIT = '-3 days';

    public function __construct(
        private readonly MessageRelayRepository $relays,
        private readonly PrincipalRepository $principals,
        private readonly ConversationRepository $conversations,
        private readonly RunScheduler $scheduler,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** files of the answer go into the sandbox of the one who asked, as files of their own message would */
        private readonly ?\OpenCCK\App\Agent\Media\AttachmentIntake $intake = null,
        private readonly ?PolicyService $policies = null,
    ) {}

    /** True when the message answered a question and went back to who asked: it is not a request of its own then. */
    public function answer(Principal $sender, IncomingMessage $message): bool {
        $text = trim($message->text);
        $files = $message->attachments !== [] && $this->intake !== null && $this->policies !== null;
        if (!$message->isPrivateChat || ($text === '' && !$files) || str_starts_with($text, '/')) {
            return false;
        }
        $relay = $this->relays->takeOpen($message->channel, $message->chatId, new DateTimeImmutable(self::WAIT));
        if ($relay === null) {
            return false;
        }
        $asker = $this->principals->find($relay->originPrincipalId);
        $meta = $this->conversations->meta($relay->originConversationId);
        if ($asker === null || $meta === null) {
            $this->logger->warning('Relay lost its conversation', ['relay' => $relay->id]);
            return false;
        }
        $name = $sender->displayName ?? '#' . $sender->id;
        // spec: BRIEF.md#входящие-вложения — the files of the answer are taken as the asker's own, kept in its sandbox
        $taken = '';
        if ($this->intake !== null && $this->policies !== null && $message->attachments !== []) {
            $taken = $this->intake->intake($asker, $this->policies->forPrincipal($asker), $message, new \Amp\NullCancellation(), $relay->originConversationId)->text;
        }
        $body = sprintf(t('relay.answer'), MessageMeta::inline($name), $relay->originRunId ?? 0, MessageMeta::inline($relay->question))
            . "\n" . UntrustedWrapper::wrap('direct-reply', trim($text . "\n\n" . $taken));
        $shared = str_starts_with($meta->chatId, '-');
        $relayed = new IncomingMessage(
            $meta->channel,
            $meta->chatId,
            $this->principals->externalId($asker->id, $meta->channel) ?? '',
            null,
            $asker->displayName,
            $body,
            '0',
            null,
            !$shared,
            new DateTimeImmutable(),
            'relay-' . $relay->id,
            $meta->threadId === '' ? null : $meta->threadId,
        );
        $this->channel->send(new OutgoingMessage($message->chatId, sprintf(t('relay.passed'), $asker->displayName ?? '#' . $asker->id), $message->messageId));
        $this->logger->info('Answer relayed', ['relay' => $relay->id, 'conversation' => $relay->originConversationId]);
        // in a group the agent may stay silent there after answering in private, as it was asked
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — someone's answer is data from outside: no saved grant in its run
        $this->scheduler->submit($asker, $relayed, $relay->originConversationId, $shared, 'relay');
        return true;
    }
}
