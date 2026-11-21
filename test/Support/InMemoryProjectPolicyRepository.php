<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ProjectPolicyRepository;

final class InMemoryProjectPolicyRepository implements ProjectPolicyRepository {
    /** @var array<int, array<string, mixed>> */
    public array $policies = [];

    public function find(int $projectId): ?array {
        return $this->policies[$projectId] ?? null;
    }

    public function save(int $projectId, array $policy): void {
        $this->policies[$projectId] = $policy;
    }

    public function projects(): array {
        $ids = array_keys($this->policies);
        sort($ids);
        return $ids;
    }
}
