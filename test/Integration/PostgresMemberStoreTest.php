<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresMemberRepository;
use OpenCCK\Test\Support\MemberStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMemberStoreTest extends MemberStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->principals = new PostgresMemberRepository($this->db);
        $this->externalId = 'visitor-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
