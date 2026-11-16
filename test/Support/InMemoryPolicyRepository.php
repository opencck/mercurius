<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\PolicyRepository;

final class InMemoryPolicyRepository implements PolicyRepository {
    /** @var array<int, array<string, mixed>> */
    public array $policies = [];

    public function find(int $principalId): ?array {
        return $this->policies[$principalId] ?? null;
    }

    public function save(int $principalId, array $overrides): void {
        $this->policies[$principalId] = $overrides;
    }
}
