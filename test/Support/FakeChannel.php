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

    public function send(OutgoingMessage $message): SendResult {
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

    /** @return list<string> texts of the given action */
    public function texts(string $action): array {
        return array_values(array_map(
            static fn(array $entry): string => $entry[2],
            array_filter($this->log, static fn(array $entry): bool => $entry[0] === $action),
        ));
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void {
        $this->answers[] = [$callbackId, $text, $alert];
    }

    public function sendFile(string $chatId, string $fileName, string $content, ?string $caption = null): SendResult {
        if ($this->sendFileFails) {
            return SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'down');
        }
        $this->files[] = [$chatId, $fileName, $content];
        return SendResult::ok((string) $this->nextId++);
    }
}
