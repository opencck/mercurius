<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Memory\Infrastructure\PostgresMemoryStore;
use OpenCCK\Test\Support\MemoryStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMemoryStoreTest extends MemoryStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [, $this->conversationId] = TestDatabase::conversation($this->db);
        $this->store = new PostgresMemoryStore($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
