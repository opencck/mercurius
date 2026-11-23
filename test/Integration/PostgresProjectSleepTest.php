<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectSleepRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectTalk;
use OpenCCK\Test\Support\TestDatabase;

/**
 * Sleep of a project in the database: turned on and off, and the talk of the day in the places of the project.
 *
 * spec: brief/memory.md#сон-проекта
 *
 * @group integration
 */
final class PostgresProjectSleepTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    public function testSleepIsOffUntilTurnedOnAndTheTalkOfTheDayComesFromThePlacesOfTheProject(): void {
        $projects = new PostgresProjectRepository($this->db);
        $project = $projects->create('Сон ' . bin2hex(random_bytes(4)), '');
        $sleep = new PostgresProjectSleepRepository($this->db);
        self::assertFalse($sleep->on($project->id));
        $sleep->set($project->id, true);
        $sleep->set($project->id, true);
        self::assertTrue($sleep->on($project->id));
        self::assertContains($project->id, $sleep->sleeping());

        $chat = 'sleep-' . bin2hex(random_bytes(4));
        [, $conversation] = TestDatabase::conversation($this->db, $chat);
        [, $elsewhere] = TestDatabase::conversation($this->db, $chat . '-other');
        $this->db->execute("INSERT INTO chat_projects (channel, chat_id, thread_id, project_id) VALUES ('test', ?, '', ?)", [$chat, $project->id]);
        $say = fn(int $conversationId, string $role, string $text, string $at = 'now()'): \Amp\Postgres\PostgresResult => $this->db->execute(
            "INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, {$at})",
            [$conversationId, $role, $text],
        );
        $say($conversation, 'user', 'вчерашнее', "now() - interval '2 days'");
        $say($conversation, 'user', "бэкапы\nв /srv/backup");
        $say($conversation, 'assistant', 'понял');
        $say($elsewhere, 'user', 'чужая беседа');

        $talk = new PostgresProjectTalk($this->db);
        self::assertSame("user: бэкапы в /srv/backup\nassistant: понял", $talk->ofDay($project->id, 1000));
        self::assertSame('assistant: понял', $talk->ofDay($project->id, 20), 'the newest within the length');

        $sleep->set($project->id, false);
        self::assertFalse($sleep->on($project->id));
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
