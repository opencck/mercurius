<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\ApprovalStoreContract;
use OpenCCK\Test\Support\InMemoryApprovalRepository;

final class InMemoryApprovalStoreTest extends ApprovalStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->approvals = new InMemoryApprovalRepository(static fn(int $runId): int => 1);
        $this->runId = 1;
        $this->principalId = 1;
        $this->conversationId = 1;
    }
}
