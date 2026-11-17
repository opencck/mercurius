<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;
use OpenCCK\Domain\Server\Server;

/**
 * spec: BRIEF.md#фаза-7-по-частям — the registry of servers
 */
interface ServerRepository {
    /** @throws DomainException when a server with this name exists */
    public function add(string $name, string $host, int $port, string $username, ?int $secretId, string $group): Server;

    public function find(int $id): ?Server;

    public function byName(string $name): ?Server;

    /** @return list<Server> */
    public function all(): array;

    /** A key seen at the first connection waits for the owner. */
    public function setPendingHostKey(int $id, string $fingerprint): void;

    /** The owner's answer: trust the pending key, or drop it. */
    public function decideHostKey(int $id, bool $trust): ?Server;

    public function remove(int $id): bool;
}
