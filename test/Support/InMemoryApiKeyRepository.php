<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ApiKeyRepository;

final class InMemoryApiKeyRepository implements ApiKeyRepository {
    /** @var array<int, array{id: int, principal: int, name: string, hash: string, created: int, used: ?int, revoked: bool}> */
    private array $keys = [];

    public function add(int $principalId, string $name, string $hash): int {
        $id = count($this->keys) + 1;
        $this->keys[$id] = ['id' => $id, 'principal' => $principalId, 'name' => $name, 'hash' => $hash, 'created' => time(), 'used' => null, 'revoked' => false];
        return $id;
    }

    public function principalOf(string $hash): ?int {
        foreach ($this->keys as $id => $key) {
            if ($key['hash'] === $hash && !$key['revoked']) {
                $this->keys[$id]['used'] = time();
                return $key['principal'];
            }
        }
        return null;
    }

    public function active(): array {
        $out = [];
        foreach ($this->keys as $key) {
            if (!$key['revoked']) {
                $out[] = ['id' => $key['id'], 'principal' => $key['principal'], 'name' => $key['name'], 'created' => $key['created'], 'used' => $key['used']];
            }
        }
        return $out;
    }

    public function revoke(int $id): bool {
        if (!isset($this->keys[$id]) || $this->keys[$id]['revoked']) {
            return false;
        }
        $this->keys[$id]['revoked'] = true;
        return true;
    }
}
