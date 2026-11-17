<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use DateTimeImmutable;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Service\MessageMeta;
use PHPUnit\Framework\TestCase;

/**
 * What the agent learns about a message besides its text: its number, topic and what it answers.
 */
final class MessageMetaTest extends TestCase {
    /** @param array<string, mixed> $raw */
    private static function message(array $raw, ?int $thread = null): IncomingMessage {
        return new IncomingMessage('telegram', '-100', '7', 'u7', 'User', 'и что это?', '55', null, false, new DateTimeImmutable(), '1', $thread === null ? null : (string) $thread, raw: $raw);
    }

    public function testAPlainMessageGetsNoHeader(): void {
        self::assertSame('', MessageMeta::header(self::message([]), '999'));
        self::assertSame('[Сообщение #55]', MessageMeta::header(self::message([]), '999', true));
    }

    public function testAReplyToTheAgentQuotesTheWholeText(): void {
        $long = str_repeat('слово ', 100) . 'конец';
        $raw = ['reply_to_message' => ['message_id' => 50, 'from' => ['id' => 999, 'first_name' => 'Bot'], 'text' => $long]];

        $header = MessageMeta::header(self::message($raw), '999');

        self::assertStringStartsWith('[Сообщение #55; ответ на #50 (твоё сообщение): «слово', $header);
        self::assertStringContainsString('конец»]', $header, 'the whole text, not a preview');
    }

    public function testATopicRootIsTheTopicNotAReply(): void {
        $raw = ['reply_to_message' => ['message_id' => 12, 'forum_topic_created' => ['name' => 'Дом']]];

        self::assertSame('[Сообщение #55; тема «Дом» (#12)]', MessageMeta::header(self::message($raw, 12), '999'));
    }

    public function testAReplyToSomeoneElseNamesThemAndHidesSecrets(): void {
        $raw = ['reply_to_message' => ['message_id' => 40, 'from' => ['id' => 5, 'first_name' => 'Анна'], 'caption' => 'ключ sk-or-v1-' . str_repeat('a', 40)]];

        $reply = (string) MessageMeta::reply(self::message($raw), '999');

        self::assertStringStartsWith('ответ на #40 (Анна): «ключ ', $reply);
        self::assertStringNotContainsString(str_repeat('a', 40), $reply);
    }
}
