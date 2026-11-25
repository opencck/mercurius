<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresMessageRelayRepository;
use OpenCCK\Infrastructure\Repository\PostgresMemberRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMessageRelayRepositoryTest extends AsyncTestCase {
    public function testAnAnswerTakesTheOldestQuestionOnce(): void {
        $db = TestDatabase::connect();
        $principals = new PostgresMemberRepository($db, 'telegram');
        $owner = $principals->ensureOwner('telegram', 'relay-owner', 'Owner');
        $chat = 'relay-' . bin2hex(random_bytes(4));
        $roman = $principals->createVisitor('telegram', $chat, 'Roman');
        $conversations = new PostgresConversationRepository($db);
        $conversation = $conversations->openConversation($owner->id, 'telegram', '-100' . random_int(1, 999999));
        $other = $conversations->openConversation($owner->id, 'telegram', '-200' . random_int(1, 999999));
        $relays = new PostgresMessageRelayRepository($db);

        $first = $relays->create('telegram', $chat, $roman->id, $conversation, $owner->id, null, 'Как дела?');
        $relays->create('telegram', $chat, $roman->id, $other, $owner->id, null, 'И ещё вопрос');

        $taken = $relays->takeOpen('telegram', $chat, new DateTimeImmutable('-1 day'));
        self::assertNotNull($taken);
        self::assertSame($first, $taken->id);
        self::assertSame('Как дела?', $taken->question);
        self::assertSame($conversation, $taken->originConversationId);
        self::assertNull($taken->originRunId);
        self::assertSame('И ещё вопрос', $relays->takeOpen('telegram', $chat, new DateTimeImmutable('-1 day'))?->question);
        self::assertNull($relays->takeOpen('telegram', $chat, new DateTimeImmutable('-1 day')), 'each question is answered once');
        $relays->create('telegram', $chat, $roman->id, $conversation, $owner->id, 5, 'Первый');
        $relays->create('telegram', $chat, $roman->id, $conversation, $owner->id, 6, 'Заново');
        /** @var \OpenCCK\Domain\Relay\MessageRelay|null $again */
        $again = $relays->takeOpen('telegram', $chat, new DateTimeImmutable('-2 days'));
        self::assertSame('Заново', $again?->question, 'asking again from the same conversation replaces the question');
        self::assertNull($relays->takeOpen('telegram', $chat, new DateTimeImmutable('-1 day')));
        $relays->create('telegram', $chat, $roman->id, $conversation, $owner->id, 5, 'Старый');
        self::assertNull($relays->takeOpen('telegram', $chat, new DateTimeImmutable('+1 minute')), 'a question older than the wait is not taken');
        $db->close();
    }
}
