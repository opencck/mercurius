<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\MemberStoreContract;

final class InMemoryMemberStoreTest extends MemberStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryMemberRepository();
        $this->externalId = '99';
    }
}
