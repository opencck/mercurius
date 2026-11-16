<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\PrincipalStoreContract;

final class InMemoryPrincipalStoreTest extends PrincipalStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryPrincipalRepository();
        $this->externalId = '99';
    }
}
