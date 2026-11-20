<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * A forum topic has a conversation of its own; the chat itself keeps one too.
 *
 * @group integration
 */
final class PostgresConversationTopicTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testEachTopicOpensAndClosesItsOwnConversation(): void {
        [$principalId] = TestDatabase::conversation($this->db);
        $conversations = new PostgresConversationRepository($this->db);

        $chat = $conversations->openConversation($principalId, 'telegram', '-100777');
        $topic = $conversations->openConversation($principalId, 'telegram', '-100777', '12');

        self::assertNotSame($chat, $topic);
        self::assertSame($topic, $conversations->openConversation($principalId, 'telegram', '-100777', '12'));
        self::assertSame('12', $conversations->meta($topic)?->threadId);
        self::assertSame('', $conversations->meta($chat)?->threadId);

        // spec: brief/participants.md#контекст-по-месту-и-аудитории — the kind of a chat is kept
        self::assertNull($conversations->privateChat($chat));
        $conversations->setPrivateChat($chat, false);
        self::assertFalse($conversations->privateChat($chat));
        self::assertTrue($conversations->isOpen($topic));
        $conversations->close('telegram', '-100777', '12');
        self::assertFalse($conversations->isOpen($topic), 'closed by /new');
        self::assertTrue($conversations->isOpen($chat));
        self::assertNotSame($topic, $conversations->openConversation($principalId, 'telegram', '-100777', '12'));
        self::assertSame($chat, $conversations->openConversation($principalId, 'telegram', '-100777'), 'closing a topic leaves the chat alone');
    }
}
