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

    /** A new value of an existing secret; its author and id stay. False when there is no such secret. */
    public function replace(string $name, string $kind, string $nonce, string $ciphertext): bool;

    /**
     * spec: brief/participants.md#секреты-проекта — a secret of a project: its own name in the project, one site
     *
     * @throws DomainException when the project has a secret with this name
     */
    public function storeIn(int $projectId, string $site, string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int;

    /** @return array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int, site: string}|null */
    public function findIn(string $name, int $projectId): ?array;

    /** @return list<array{name: string, kind: string, site: string}> */
    public function ofProject(int $projectId): array;

    public function replaceIn(string $name, int $projectId, string $site, string $kind, string $nonce, string $ciphertext): bool;

    public function deleteIn(string $name, int $projectId): bool;

    /**
     * Secrets of the agent: the widest layer, kept by the owners of the agent; an agent secret and a personal one may
     * share a name.
     *
     * @return array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null
     */
    public function findAgent(string $name): ?array;

    /** @throws DomainException when an agent secret with this name exists */
    public function storeAgent(string $name, string $kind, string $nonce, string $ciphertext, ?int $principalId): int;

    public function replaceAgent(string $name, string $kind, string $nonce, string $ciphertext): bool;

    public function deleteAgent(string $name): bool;

    /** @return list<array{name: string, kind: string}> */
    public function ofAgent(): array;
}
