<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;
use OpenCCK\Test\Support\PrincipalStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresPrincipalStoreTest extends PrincipalStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->principals = new PostgresPrincipalRepository($this->db);
        $this->externalId = 'visitor-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
