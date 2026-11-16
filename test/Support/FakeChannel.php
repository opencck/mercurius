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

    public function send(OutgoingMessage $message): SendResult {
        $id = (string) $this->nextId++;
        $this->log[] = ['send', $message->chatId, $message->text];
        $this->modes[] = ['send', $message->text, $message->parseMode];
        return $this->sendFails
            ? SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'down')
            : SendResult::ok($id);
    }

    public function edit(string $chatId, string $messageId, string $text, ParseMode $parseMode = ParseMode::Plain): SendResult {
        $this->log[] = ['edit', $messageId, $text];
        $this->modes[] = ['edit', $text, $parseMode];
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
}
