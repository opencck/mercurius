<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\ExperienceRepositoryContract;
use OpenCCK\Test\Support\InMemoryExperienceRepository;

final class InMemoryExperienceRepositoryTest extends ExperienceRepositoryContract {
    private InMemoryExperienceRepository $store;

    protected function setUp(): void {
        parent::setUp();
        $this->experience = $this->store = new InMemoryExperienceRepository();
    }

    protected function conversation(string $channel, string $chatId): int {
        $id = count($this->store->conversations) + 1;
        $this->store->conversations[$id] = [$channel, $chatId];
        return $id;
    }
}
