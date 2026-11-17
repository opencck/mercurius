<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresChannelRepository;
use OpenCCK\Test\Support\ChannelRepositoryContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresChannelRepositoryTest extends ChannelRepositoryContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->channels = new PostgresChannelRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
