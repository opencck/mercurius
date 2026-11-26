<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Domain\Repository\SshHostKeyRepository;
use OpenCCK\Test\Support\InMemorySshHostKeyRepository;
use OpenCCK\Test\Support\SshHostKeyStoreContract;

final class InMemorySshHostKeyStoreTest extends SshHostKeyStoreContract {
    protected function store(): SshHostKeyRepository {
        return new InMemorySshHostKeyRepository();
    }
}
