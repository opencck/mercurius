<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате — the context is the conversation's: a private chat
 * gets the project of its conversation, /new takes it away, a group keeps the binding of its chat
 *
 * @group integration
 */
final class PostgresConversationContextTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testAPrivateChatGetsTheProjectOfItsConversation(): void {
        [$principalId] = TestDatabase::conversation($this->db);
        $conversations = new PostgresConversationRepository($this->db);
        $projects = new PostgresProjectRepository($this->db);
        $chat = (string) random_int(100000, 999999999);
        $project = $projects->create('context-' . $chat, 'goal');
        $active = static fn(): ?int => $projects->active('telegram', $chat)?->id;
        $bound = $projects->create('bound-' . $chat, 'goal');

        $id = $conversations->openConversation($principalId, 'telegram', $chat);
        self::assertNull($conversations->meta($id)?->context);
        self::assertNull($active());

        $conversations->setContext($id, 'project:' . $project->id);
        self::assertSame('project:' . $project->id, $conversations->meta($id)?->context);
        self::assertSame($project->id, $active());

        $projects->activate('telegram', $chat, $bound->id);
        self::assertSame($project->id, $active(), 'the conversation comes before the binding of the chat');

        $conversations->setContext($id, 'owners');
        self::assertSame($bound->id, $active(), 'the owners context names no project');

        $conversations->setContext($id, 'project:' . $project->id);
        $conversations->close('telegram', $chat);
        self::assertSame($bound->id, $active(), '/new leaves the binding of the chat');
    }

    public function testAGroupKeepsTheBindingOfItsChat(): void {
        [$principalId] = TestDatabase::conversation($this->db);
        $conversations = new PostgresConversationRepository($this->db);
        $projects = new PostgresProjectRepository($this->db);
        $chat = '-' . random_int(100000, 999999999);
        $project = $projects->create('group-' . $chat, 'goal');
        $active = static fn(): ?int => $projects->active('telegram', $chat)?->id;

        $id = $conversations->openConversation($principalId, 'telegram', $chat);
        $conversations->setContext($id, 'project:' . $project->id);
        self::assertNull($active());
    }
}
