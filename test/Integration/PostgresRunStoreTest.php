<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository;
use OpenCCK\Test\Support\RunStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresRunStoreTest extends RunStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [$this->principalId, $this->conversationId] = TestDatabase::conversation($this->db);
        $this->runs = new PostgresRunRepository($this->db);
        $this->transcript = new PostgresTranscriptRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
