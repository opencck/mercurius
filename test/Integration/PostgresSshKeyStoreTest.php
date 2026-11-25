<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Infrastructure\Repository\PostgresSshKeyRepository;
use OpenCCK\Test\Support\SshKeyStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresSshKeyStoreTest extends SshKeyStoreContract {
    protected function store(): SshKeyRepository {
        return new PostgresSshKeyRepository(TestDatabase::connect());
    }
}
