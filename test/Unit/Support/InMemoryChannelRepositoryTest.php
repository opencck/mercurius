<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\ChannelRepositoryContract;
use OpenCCK\Test\Support\InMemoryChannelRepository;

final class InMemoryChannelRepositoryTest extends ChannelRepositoryContract {
    protected function setUp(): void {
        parent::setUp();
        $this->channels = new InMemoryChannelRepository();
    }
}
