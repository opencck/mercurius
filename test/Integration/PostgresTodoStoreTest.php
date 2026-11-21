<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Mercurius\Todo\Infrastructure\PostgresTodoStore;
use Mercurius\Todo\Testing\TodoStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * The plans of conversations on PostgreSQL behave as the contract of the package says.
 *
 * spec: brief/agent-loop.md#список-дел-беседы
 *
 * @group integration
 */
final class PostgresTodoStoreTest extends TodoStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->store = new PostgresTodoStore(TestDatabase::connect());
    }
}
