<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceRepository;
use OpenCCK\Test\Support\TestDatabase;
use OpenCCK\Test\Support\TraceStoreContract;

/**
 * @group integration
 */
final class PostgresTraceStoreTest extends TraceStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [$principalId, $conversationId] = TestDatabase::conversation($this->db);
        $run = (new PostgresRunRepository($this->db))->start($conversationId, $principalId, 'm/x', new RunLimits(30, 1000, 900, 120));
        self::assertNotNull($run);
        $this->runId = $run->id;
        // Payloads of earlier test runs would be purged too and skew the counts.
        $this->db->query('TRUNCATE trace_payloads');
        $this->traces = new PostgresTraceRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
