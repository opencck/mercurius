<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Domain\Repository\McpInstallRepository;
use OpenCCK\Test\Support\InMemoryMcpInstallRepository;
use OpenCCK\Test\Support\McpInstallStoreContract;

final class InMemoryMcpInstallStoreTest extends McpInstallStoreContract {
    protected function store(): McpInstallRepository {
        return new InMemoryMcpInstallRepository();
    }
}
