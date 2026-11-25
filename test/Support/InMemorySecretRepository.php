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

    public function replace(string $name, string $kind, string $nonce, string $ciphertext): bool {
        $secret = $this->find($name);
        if ($secret === null) {
            return false;
        }
        $this->secrets[$secret['id']] = ['kind' => $kind, 'nonce' => $nonce, 'ciphertext' => $ciphertext] + $secret;
        return true;
    }

    public function delete(string $name): bool {
        $secret = $this->find($name);
        if ($secret === null) {
            return false;
        }
        unset($this->secrets[$secret['id']]);
        return true;
    }

    /** @var array<int, array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int, site: string, project: int}> */
    public array $projectSecrets = [];

    public function storeIn(int $projectId, string $site, string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int {
        if ($this->findIn($name, $projectId) !== null) {
            throw new DomainException(sprintf('Секрет «%s» уже есть.', $name));
        }
        $id = 1000 + count($this->projectSecrets) + 1;
        $this->projectSecrets[$id] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'nonce' => $nonce, 'ciphertext' => $ciphertext, 'principal' => $principalId, 'site' => $site, 'project' => $projectId];
        return $id;
    }

    public function findIn(string $name, int $projectId): ?array {
        foreach ($this->projectSecrets as $secret) {
            if ($secret['project'] === $projectId && mb_strtolower($secret['name']) === mb_strtolower($name)) {
                return ['id' => $secret['id'], 'name' => $secret['name'], 'kind' => $secret['kind'], 'nonce' => $secret['nonce'], 'ciphertext' => $secret['ciphertext'], 'principal' => $secret['principal'], 'site' => $secret['site']];
            }
        }
        return null;
    }

    public function ofProject(int $projectId): array {
        $found = [];
        foreach ($this->projectSecrets as $secret) {
            if ($secret['project'] === $projectId) {
                $found[] = ['name' => $secret['name'], 'kind' => $secret['kind'], 'site' => $secret['site']];
            }
        }
        return $found;
    }

    public function replaceIn(string $name, int $projectId, string $site, string $kind, string $nonce, string $ciphertext): bool {
        $secret = $this->findIn($name, $projectId);
        if ($secret === null) {
            return false;
        }
        $this->projectSecrets[$secret['id']] = ['kind' => $kind, 'nonce' => $nonce, 'ciphertext' => $ciphertext, 'site' => $site] + $this->projectSecrets[$secret['id']];
        return true;
    }

    public function deleteIn(string $name, int $projectId): bool {
        $secret = $this->findIn($name, $projectId);
        if ($secret === null) {
            return false;
        }
        unset($this->projectSecrets[$secret['id']]);
        return true;
    }

    /** @var array<int, array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}> */
    public array $agentSecrets = [];

    public function findAgent(string $name): ?array {
        foreach ($this->agentSecrets as $secret) {
            if (mb_strtolower($secret['name']) === mb_strtolower($name)) {
                return $secret;
            }
        }
        return null;
    }

    public function storeAgent(string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int {
        if ($this->findAgent($name) !== null) {
            throw new DomainException(sprintf('Секрет «%s» уже есть.', $name));
        }
        $id = 5000 + count($this->agentSecrets) + 1;
        $this->agentSecrets[$id] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'nonce' => $nonce, 'ciphertext' => $ciphertext, 'principal' => $principalId];
        return $id;
    }

    public function replaceAgent(string $name, string $kind, string $nonce, string $ciphertext): bool {
        $secret = $this->findAgent($name);
        if ($secret === null) {
            return false;
        }
        $this->agentSecrets[$secret['id']] = ['kind' => $kind, 'nonce' => $nonce, 'ciphertext' => $ciphertext] + $secret;
        return true;
    }

    public function deleteAgent(string $name): bool {
        $secret = $this->findAgent($name);
        if ($secret === null) {
            return false;
        }
        unset($this->agentSecrets[$secret['id']]);
        return true;
    }

    public function ofAgent(): array {
        return array_values(array_map(static fn(array $s): array => ['name' => $s['name'], 'kind' => $s['kind']], $this->agentSecrets));
    }
}
