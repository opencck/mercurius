<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Domain\Repository\McpServerRepository;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;
use OpenCCK\Test\Support\McpServerStoreContract;

final class InMemoryMcpServerStoreTest extends McpServerStoreContract {
    protected function store(): McpServerRepository {
        return new InMemoryMcpServerRepository();
    }
}
