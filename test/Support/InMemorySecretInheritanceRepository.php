<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SecretInheritanceRepository;

final class InMemorySecretInheritanceRepository implements SecretInheritanceRepository {
    /** @var array<string, array{owner: bool, agent: bool, by: ?int}> */
    private array $contexts = [];

    public function of(string $context): array {
        return $this->contexts[$context] ?? ['owner' => false, 'agent' => false, 'by' => null];
    }

    public function set(string $context, string $layer, bool $on, ?int $by = null): void {
        $now = $this->of($context);
        if ($layer === 'owner') {
            $now['owner'] = $on;
            $now['by'] = $by;
        } else {
            $now['agent'] = $on;
        }
        $this->contexts[$context] = $now;
    }
}
