<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelRoleRepository;
use OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository;
use OpenCCK\Test\Support\ModelStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresModelStoreTest extends ModelStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        // One role table for the whole agent: each test starts from an empty one.
        $this->db->query('TRUNCATE model_roles');
        $this->catalog = new PostgresModelCatalogRepository($this->db);
        $this->roles = new PostgresModelRoleRepository($this->db);
        $this->decisions = new PostgresRoutingDecisionRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
