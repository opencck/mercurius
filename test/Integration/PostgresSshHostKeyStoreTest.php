<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use OpenCCK\Domain\Repository\SshHostKeyRepository;
use OpenCCK\Infrastructure\Repository\PostgresSshHostKeyRepository;
use OpenCCK\Test\Support\SshHostKeyStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresSshHostKeyStoreTest extends SshHostKeyStoreContract {
    protected function store(): SshHostKeyRepository {
        return new PostgresSshHostKeyRepository(TestDatabase::connect());
    }
}
