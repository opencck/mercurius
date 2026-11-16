<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\InMemoryTraceRepository;
use OpenCCK\Test\Support\TraceStoreContract;

final class InMemoryTraceStoreTest extends TraceStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->traces = new InMemoryTraceRepository();
        $this->runId = 1;
    }
}
