<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use OpenCCK\Test\Support\InMemoryMemoryStore;
use OpenCCK\Test\Support\MemoryStoreContract;

final class InMemoryMemoryStoreTest extends MemoryStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->store = new InMemoryMemoryStore();
        $this->conversationId = 1;
    }
}
