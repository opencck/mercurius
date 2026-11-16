<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ModelStoreContract;

final class InMemoryModelStoreTest extends ModelStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $stores = new InMemoryModelStores();
        $this->catalog = $stores;
        $this->roles = $stores;
        $this->decisions = $stores;
    }
}
