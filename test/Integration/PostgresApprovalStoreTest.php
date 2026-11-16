<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Infrastructure\Repository\PostgresApprovalRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Test\Support\ApprovalStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresApprovalStoreTest extends ApprovalStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [$this->principalId, $this->conversationId] = TestDatabase::conversation($this->db);
        $run = (new PostgresRunRepository($this->db))->start($this->conversationId, $this->principalId, 'm/x', new RunLimits(30, 1000, 900, 120));
        self::assertNotNull($run);
        $this->runId = $run->id;
        $this->approvals = new PostgresApprovalRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
