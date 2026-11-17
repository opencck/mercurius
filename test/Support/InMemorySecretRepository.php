<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Repository\SecretRepository;

final class InMemorySecretRepository implements SecretRepository {
    /** @var array<int, array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}> */
    public array $secrets = [];

    public function store(string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int {
        if ($this->find($name) !== null) {
            throw new DomainException(sprintf('Секрет «%s» уже есть.', $name));
        }
        $id = count($this->secrets) + 1;
        $this->secrets[$id] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'nonce' => $nonce, 'ciphertext' => $ciphertext, 'principal' => $principalId];
        return $id;
    }

    public function find(string $name): ?array {
        foreach ($this->secrets as $secret) {
            if (mb_strtolower($secret['name']) === mb_strtolower($name)) {
                return $secret;
            }
        }
        return null;
    }

    public function byId(int $id): ?array {
        return $this->secrets[$id] ?? null;
    }

    public function all(): array {
        return array_values(array_map(static fn(array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'principal' => $s['principal']], $this->secrets));
    }

    public function delete(string $name): bool {
        $secret = $this->find($name);
        if ($secret === null) {
            return false;
        }
        unset($this->secrets[$secret['id']]);
        return true;
    }
}
