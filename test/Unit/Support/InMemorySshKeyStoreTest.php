<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Test\Support\InMemorySshKeyRepository;
use OpenCCK\Test\Support\SshKeyStoreContract;

final class InMemorySshKeyStoreTest extends SshKeyStoreContract {
    protected function store(): SshKeyRepository {
        return new InMemorySshKeyRepository();
    }
}
