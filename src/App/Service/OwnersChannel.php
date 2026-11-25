<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use Fiber;
use Mercurius\Messenger\Capabilities;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\ContactChannel;
use Mercurius\Messenger\ForumChannel;
use Mercurius\Messenger\LocationChannel;
use Mercurius\Messenger\MediaKind;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\PollChannel;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use OpenCCK\Domain\Repository\OwnerCardRepository;

use function Mercurius\I18n\t;

/**
 * The channel with the chat of the owners: a message to OWNERS goes to the private chat of every owner, and its copies
 * are kept; editing any copy (or the card by its id) edits every copy and adds who decided, when the edit comes from
 * an owner's press. Everything else passes to the channel as it is.
 *
 * spec: brief/participants.md#свои-роли — cards and notices go to every owner, any presses, every copy shows the decision
 */
final class OwnersChannel implements Channel, ForumChannel, PollChannel, ContactChannel, LocationChannel {
    /** the chat id that means "every owner"; never a chat of Telegram or of the WebSocket API */
    public const OWNERS = 'owners';

    /** @var array<int, string> fiber object id => the owner whose press is being handled in that fiber */
    private array $pressers = [];
    private ?string $mainPresser = null;

    public function __construct(
        private readonly Channel $inner,
        private readonly OwnerDirectory $owners,
        private readonly OwnerCardRepository $cards,
    ) {}

    /**
     * Runs the handling of an owner's press: an edit of a card in it says who decided.
     *
     * @template T
     * @param Closure(): T $handle
     * @return T
     */
    public function pressedBy(?string $name, Closure $handle): mixed {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainPresser = $name;
        } elseif ($name !== null) {
            $this->pressers[spl_object_id($fiber)] = $name;
        }
        try {
            return $handle();
        } finally {
            if ($fiber === null) {
                $this->mainPresser = null;
            } else {
                unset($this->pressers[spl_object_id($fiber)]);
            }
        }
    }

    private function presser(): ?string {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? $this->mainPresser : ($this->pressers[spl_object_id($fiber)] ?? null);
    }

    public function name(): string {
        return $this->inner->name();
    }

    public function send(OutgoingMessage $message): SendResult {
        if ($message->chatId !== self::OWNERS) {
            return $this->inner->send($message);
        }
        $copies = [];
        $last = null;
        foreach ($this->owners->chatIds() as $chat) {
            $last = $this->inner->send(new OutgoingMessage($chat, $message->text, null, $message->parseMode, null, $message->disableLinkPreview, $message->buttons));
            if ($last->success && $last->messageId !== null) {
                $copies[] = [$chat, $last->messageId];
            }
        }
        if ($copies === []) {
            return $last ?? SendResult::failed(SendErrorKind::NotFound, 'no owner to reach');
        }
        return SendResult::ok((string) $this->cards->create($copies));
    }

    public function edit(string $chatId, string $messageId, string $text, ParseMode $parseMode = ParseMode::Plain, array $buttons = []): SendResult {
        $card = $chatId === self::OWNERS ? (ctype_digit($messageId) ? (int) $messageId : null) : $this->cards->cardOf($chatId, $messageId);
        if ($card === null) {
            return $this->inner->edit($chatId, $messageId, $text, $parseMode, $buttons);
        }
        $presser = $this->presser();
        $shown = $presser === null ? $text : $text . "\n\n" . sprintf(t('owners.decided_by'), $presser);
        $result = SendResult::failed(SendErrorKind::NotFound, 'no copy of the card');
        foreach ($this->cards->copies($card) as [$chat, $message]) {
            $edited = $this->inner->edit($chat, $message, $shown, $parseMode, $buttons);
            $result = $result->success ? $result : $edited;
        }
        return $result;
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void {
        $this->inner->answerCallback($callbackId, $text, $alert);
    }

    public function delete(string $chatId, string $messageId): bool {
        $card = $chatId === self::OWNERS ? (ctype_digit($messageId) ? (int) $messageId : null) : null;
        if ($card === null) {
            return $this->inner->delete($chatId, $messageId);
        }
        $deleted = false;
        foreach ($this->cards->copies($card) as [$chat, $message]) {
            $deleted = $this->inner->delete($chat, $message) || $deleted;
        }
        return $deleted;
    }

    public function leave(string $chatId): bool {
        return $this->inner->leave($chatId);
    }

    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult {
        if ($chatId !== self::OWNERS) {
            return $this->inner->sendFile($chatId, $fileName, $content, $caption, $threadId);
        }
        return $this->toEveryOwner(fn(string $chat): SendResult => $this->inner->sendFile($chat, $fileName, $content, $caption));
    }

    public function sendMedia(string $chatId, MediaKind $kind, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult {
        if ($chatId !== self::OWNERS) {
            return $this->inner->sendMedia($chatId, $kind, $fileName, $content, $caption, $threadId);
        }
        return $this->toEveryOwner(fn(string $chat): SendResult => $this->inner->sendMedia($chat, $kind, $fileName, $content, $caption));
    }

    /** @param Closure(string): SendResult $send */
    private function toEveryOwner(Closure $send): SendResult {
        $result = SendResult::failed(SendErrorKind::NotFound, 'no owner to reach');
        foreach ($this->owners->chatIds() as $chat) {
            $sent = $send($chat);
            $result = $result->success ? $result : $sent;
        }
        return $result;
    }

    public function download(string $fileId, int $maxBytes): ?string {
        return $this->inner->download($fileId, $maxBytes);
    }

    public function typing(string $chatId): void {
        if ($chatId !== self::OWNERS) {
            $this->inner->typing($chatId);
        }
    }

    public function capabilities(): Capabilities {
        return $this->inner->capabilities();
    }

    public function createTopic(string $chatId, string $name): ?string {
        return $this->inner instanceof ForumChannel ? $this->inner->createTopic($chatId, $name) : null;
    }

    public function editTopic(string $chatId, string $threadId, string $name): bool {
        return $this->inner instanceof ForumChannel && $this->inner->editTopic($chatId, $threadId, $name);
    }

    public function closeTopic(string $chatId, string $threadId): bool {
        return $this->inner instanceof ForumChannel && $this->inner->closeTopic($chatId, $threadId);
    }

    public function reopenTopic(string $chatId, string $threadId): bool {
        return $this->inner instanceof ForumChannel && $this->inner->reopenTopic($chatId, $threadId);
    }

    public function deleteTopic(string $chatId, string $threadId): bool {
        return $this->inner instanceof ForumChannel && $this->inner->deleteTopic($chatId, $threadId);
    }

    public function sendPoll(string $chatId, string $question, array $options, bool $anonymous = true, bool $multiple = false, ?string $threadId = null): SendResult {
        return $this->inner instanceof PollChannel ? $this->inner->sendPoll($chatId, $question, $options, $anonymous, $multiple, $threadId) : SendResult::failed(SendErrorKind::NotFound, 'no polls');
    }

    public function sendContact(string $chatId, string $phone, string $firstName, ?string $lastName = null, ?string $threadId = null): SendResult {
        return $this->inner instanceof ContactChannel ? $this->inner->sendContact($chatId, $phone, $firstName, $lastName, $threadId) : SendResult::failed(SendErrorKind::NotFound, 'no contacts');
    }

    public function sendLocation(string $chatId, float $latitude, float $longitude, ?string $threadId = null): SendResult {
        return $this->inner instanceof LocationChannel ? $this->inner->sendLocation($chatId, $latitude, $longitude, $threadId) : SendResult::failed(SendErrorKind::NotFound, 'no locations');
    }
}
