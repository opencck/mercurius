<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;

/**
 * Encrypted secrets: the store never sees a value, only its nonce and ciphertext.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh
 */
interface SecretRepository {
    /** @throws DomainException when a secret with this name exists */
    public function store(string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int;

    /** @return array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null */
    public function find(string $name): ?array;

    /** @return array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null */
    public function byId(int $id): ?array;

    /** @return list<array{id: int, name: string, kind: string, principal: ?int}> */
    public function all(): array;

    public function delete(string $name): bool;
}
