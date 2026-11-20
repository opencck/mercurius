<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;
use OpenCCK\Domain\Server\Server;

/**
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the registry of servers
 */
interface ServerRepository {
    /** @throws DomainException when a server with this name exists */
    public function add(string $name, string $host, int $port, string $username, ?int $secretId, string $group, ?string $route = null): Server;

    public function find(int $id): ?Server;

    public function byName(string $name): ?Server;

    /** @return list<Server> */
    public function all(): array;

    /** A key seen at the first connection waits for the owner. */
    public function setPendingHostKey(int $id, string $fingerprint): void;

    /**
     * The owner's answer: trust the pending key, or drop it.
     *
     * @param string|null $fingerprint the key the owner saw: the answer holds only while it is still the pending one
     */
    public function decideHostKey(int $id, bool $trust, ?string $fingerprint = null): ?Server;

    public function remove(int $id): bool;

    /**
     * Changes a server; a new host or port, or forgetting the key, drops the trusted and the pending host key.
     *
     * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the registry is changed in the dialogue
     *
     * @throws \DomainException the new name is taken
     */
    public function update(int $id, \OpenCCK\Domain\Server\ServerChanges $changes): ?\OpenCCK\Domain\Server\Server;
}
