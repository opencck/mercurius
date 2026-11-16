<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use Amp\Sql\SqlQueryError;
use OpenCCK\Test\Support\TestDatabase;

/**
 * Runs on the project server: docker compose --profile test run --rm test.
 *
 * @group integration
 */
final class AgentSchemaTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    private function insertRun(int $conversationId, int $principalId, string $status): void {
        $this->db->execute(
            "INSERT INTO runs (conversation_id, principal_id, model, status, max_turns, token_budget, run_timeout, tool_timeout)
             VALUES (?, ?, 'm/x', ?, 30, 1000, 900, 120)",
            [$conversationId, $principalId, $status],
        );
    }

    public function testSecondActiveRunInConversationIsRejected(): void {
        [$principalId, $conversationId] = TestDatabase::conversation($this->db);
        $this->insertRun($conversationId, $principalId, 'running');

        $this->expectException(SqlQueryError::class);
        $this->insertRun($conversationId, $principalId, 'waiting_approval');
    }

    public function testFinishedRunsDoNotBlockANewOne(): void {
        [$principalId, $conversationId] = TestDatabase::conversation($this->db);
        $this->insertRun($conversationId, $principalId, 'done');
        $this->insertRun($conversationId, $principalId, 'running');

        $count = $this->db->query('SELECT count(*) AS n FROM runs')->fetchRow();
        self::assertSame(2, $count['n'] ?? null);
    }

    public function testToolRoleIsAccepted(): void {
        [, $conversationId] = TestDatabase::conversation($this->db);
        $this->db->execute(
            "INSERT INTO messages (conversation_id, role, content, tool_call_id) VALUES (?, 'tool', 'result', 'call-1')",
            [$conversationId],
        );

        $row = $this->db->query("SELECT role FROM messages")->fetchRow();
        self::assertSame('tool', $row['role'] ?? null);
    }
}
