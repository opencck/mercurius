<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Messenger\Capabilities;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\SendResult;

final class FakeChannel implements Channel {
    /** @var list<array{string, string, string}> [action, chatId or messageId, text] */
    public array $log = [];
    /** @var list<array{string, string, ParseMode}> [action, text, parse mode] */
    public array $modes = [];
    private int $nextId = 100;

    /** @param positive-int $maxLength */
    public function __construct(private readonly int $maxLength = 4096, private readonly bool $sendFails = false) {}

    public function name(): string {
        return 'telegram';
    }

    /** seconds every send waits, to imitate Telegram rate limits */
    public float $sendDelay = 0.0;
    /** @var list<SendResult> results the next edits return before the default success */
    public array $editResults = [];
    public bool $sendFileFails = false;
    /** @var list<SendResult> results the next sends return before the default success */
    public array $sendResults = [];
    /** @var array<array-key, list<list<\Mercurius\Messenger\Button>>> buttons each message got last, by message id */
    public array $buttons = [];
    /** @var list<array{string, ?string, bool}> answered callbacks: id, text, alert */
    public array $answers = [];
    /** @var list<array{string, string, string}> sent files: chat, name, content */
    public array $files = [];
    /** @var list<?string> the topic of every file sent */
    public array $fileThreads = [];

    /** @var list<?string> the forum topic of every sent message */
    public array $threads = [];
    /** @var list<bool> whether the link preview was off, for every sent message */
    public array $previewsOff = [];
    /** @var array<string, string> incoming files by their id */
    public array $downloads = [];

    public function download(string $fileId, int $maxBytes): ?string {
        $bytes = $this->downloads[$fileId] ?? null;
        return $bytes === null || strlen($bytes) > $maxBytes ? null : $bytes;
    }

    public function send(OutgoingMessage $message): SendResult {
        $this->threads[] = $message->threadId;
        $this->previewsOff[] = $message->disableLinkPreview;
        if ($this->sendDelay > 0) {
            \Amp\delay($this->sendDelay);
        }
        $id = (string) $this->nextId++;
        $this->buttons[$id] = $message->buttons;
        $scripted = array_shift($this->sendResults);
        if ($scripted !== null && !$scripted->success) {
            $this->log[] = ['send-failed', $message->chatId, $message->text];
            return $scripted;
        }
        $this->log[] = ['send', $message->chatId, $message->text];
        $this->modes[] = ['send', $message->text, $message->parseMode];
        return $this->sendFails
            ? SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'down')
            : SendResult::ok($id);
    }

    public function edit(string $chatId, string $messageId, string $text, ParseMode $parseMode = ParseMode::Plain, array $buttons = []): SendResult {
        $scripted = array_shift($this->editResults);
        if ($scripted !== null && !$scripted->success) {
            $this->log[] = ['edit-failed', $messageId, $text];
            return $scripted;
        }
        $this->log[] = ['edit', $messageId, $text];
        $this->modes[] = ['edit', $text, $parseMode];
        $this->buttons[$messageId] = $buttons;
        return SendResult::ok($messageId);
    }

    public function typing(string $chatId): void {
        $this->log[] = ['typing', $chatId, ''];
    }

    public function capabilities(): Capabilities {
        return new Capabilities(true, $this->maxLength, false);
    }

    /** Whether the link preview was off for the first sent message that contains $needle. */
    public function previewOff(string $needle): ?bool {
        $sends = array_values(array_filter($this->log, static fn(array $entry): bool => $entry[0] === 'send' || $entry[0] === 'send-failed'));
        foreach ($sends as $i => $entry) {
            if (str_contains($entry[2], $needle)) {
                return $this->previewsOff[$i] ?? null;
            }
        }
        return null;
    }

    /** @return list<string> texts of the given action */
    public function texts(string $action): array {
        return array_values(array_map(
            static fn(array $entry): string => $entry[2],
            array_filter($this->log, static fn(array $entry): bool => $entry[0] === $action),
        ));
    }

    /** @var list<array{string, string}> deleted messages: chat, message */
    public array $deleted = [];
    public bool $deleteFails = false;

    /** @var list<string> */
    public array $left = [];

    /** @var list<array{string, string, string, string}> topic changes: action, chat, thread, name */
    public array $topics = [];
    /** createTopic answers null: the chat is not a forum or the bot may not manage topics */
    public bool $topicsFail = false;
    private int $nextTopic = 500;

    public function createTopic(string $chatId, string $name): ?string {
        if ($this->topicsFail) {
            return null;
        }
        $thread = (string) $this->nextTopic++;
        $this->topics[] = ['create', $chatId, $thread, $name];
        return $thread;
    }

    public function editTopic(string $chatId, string $threadId, string $name): bool {
        $this->topics[] = ['edit', $chatId, $threadId, $name];
        return !$this->topicsFail;
    }

    public function closeTopic(string $chatId, string $threadId): bool {
        $this->topics[] = ['close', $chatId, $threadId, ''];
        return !$this->topicsFail;
    }

    public function reopenTopic(string $chatId, string $threadId): bool {
        $this->topics[] = ['reopen', $chatId, $threadId, ''];
        return !$this->topicsFail;
    }

    public function deleteTopic(string $chatId, string $threadId): bool {
        $this->topics[] = ['delete', $chatId, $threadId, ''];
        return !$this->topicsFail;
    }

    public function leave(string $chatId): bool {
        $this->left[] = $chatId;
        return true;
    }

    public function delete(string $chatId, string $messageId): bool {
        if ($this->deleteFails) {
            return false;
        }
        $this->deleted[] = [$chatId, $messageId];
        return true;
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void {
        $this->answers[] = [$callbackId, $text, $alert];
    }

    /** @var list<array{string, string, array<string, mixed>, ?string}> sent content: chat, kind, details, topic */
    public array $content = [];

    public function sendMedia(string $chatId, \Mercurius\Messenger\MediaKind $kind, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult {
        if ($kind === \Mercurius\Messenger\MediaKind::Document) {
            return $this->sendFile($chatId, $fileName, $content, $caption, $threadId);
        }
        $this->content[] = [$chatId, $kind->value, ['name' => $fileName, 'bytes' => $content, 'caption' => $caption], $threadId];
        return SendResult::ok((string) $this->nextId++);
    }

    public function sendLocation(string $chatId, float $latitude, float $longitude, ?string $threadId = null): SendResult {
        $this->content[] = [$chatId, 'location', ['latitude' => $latitude, 'longitude' => $longitude], $threadId];
        return SendResult::ok((string) $this->nextId++);
    }

    public function sendContact(string $chatId, string $phone, string $firstName, ?string $lastName = null, ?string $threadId = null): SendResult {
        $this->content[] = [$chatId, 'contact', ['phone' => $phone, 'first_name' => $firstName, 'last_name' => $lastName], $threadId];
        return SendResult::ok((string) $this->nextId++);
    }

    public function sendPoll(string $chatId, string $question, array $options, bool $anonymous = true, bool $multiple = false, ?string $threadId = null): SendResult {
        $this->content[] = [$chatId, 'poll', ['question' => $question, 'options' => $options, 'anonymous' => $anonymous, 'multiple' => $multiple], $threadId];
        return SendResult::ok((string) $this->nextId++);
    }

    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null, ?string $threadId = null): SendResult {
        if ($this->sendFileFails) {
            return SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'down');
        }
        $this->files[] = [$chatId, $fileName, $content];
        $this->fileThreads[] = $threadId;
        return SendResult::ok((string) $this->nextId++);
    }
}
