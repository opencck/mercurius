<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\DelegationStoreContract;
use OpenCCK\Test\Support\InMemoryDelegationRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;

final class InMemoryDelegationStoreTest extends DelegationStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->delegations = new InMemoryDelegationRepository();
        $this->runs = new InMemoryRunRepository();
        $this->principalId = 1;
        $this->conversationId = 1;
    }
}
