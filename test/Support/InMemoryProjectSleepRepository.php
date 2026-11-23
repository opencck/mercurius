<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ProjectSleepRepository;

final class InMemoryProjectSleepRepository implements ProjectSleepRepository {
    /** @var array<int, true> */
    private array $on = [];

    public function on(int $projectId): bool {
        return isset($this->on[$projectId]);
    }

    public function set(int $projectId, bool $on): void {
        if ($on) {
            $this->on[$projectId] = true;
        } else {
            unset($this->on[$projectId]);
        }
    }

    public function sleeping(): array {
        $ids = array_keys($this->on);
        sort($ids);
        return $ids;
    }
}
