<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SecretInheritanceRepository;

final class InMemorySecretInheritanceRepository implements SecretInheritanceRepository {
    /** @var array<string, array{owner: bool, agent: bool}> */
    private array $contexts = [];

    public function of(string $context): array {
        return $this->contexts[$context] ?? ['owner' => false, 'agent' => false];
    }

    public function set(string $context, string $layer, bool $on): void {
        $now = $this->of($context);
        $now[$layer === 'owner' ? 'owner' : 'agent'] = $on;
        $this->contexts[$context] = $now;
    }
}
