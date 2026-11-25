<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use OpenCCK\Domain\Repository\McpInstallRepository;
use OpenCCK\Infrastructure\Repository\PostgresMcpInstallRepository;
use OpenCCK\Test\Support\McpInstallStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMcpInstallStoreTest extends McpInstallStoreContract {
    protected function store(): McpInstallRepository {
        return new PostgresMcpInstallRepository(TestDatabase::connect());
    }
}
