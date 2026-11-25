<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use OpenCCK\Domain\Repository\McpServerRepository;
use OpenCCK\Infrastructure\Repository\PostgresMcpServerRepository;
use OpenCCK\Test\Support\McpServerStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMcpServerStoreTest extends McpServerStoreContract {
    protected function store(): McpServerRepository {
        return new PostgresMcpServerRepository(TestDatabase::connect());
    }
}
