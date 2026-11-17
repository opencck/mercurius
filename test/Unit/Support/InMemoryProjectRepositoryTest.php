<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ProjectRepositoryContract;

final class InMemoryProjectRepositoryTest extends ProjectRepositoryContract {
    protected function setUp(): void {
        parent::setUp();
        $this->projects = new InMemoryProjectRepository();
    }
}
