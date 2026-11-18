<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Keys of the WebSocket API by their SHA-256; the key itself is never stored.
 */
interface ApiKeyRepository {
    public function add(int $principalId, string $name, string $hash): int;

    /** The participant of a key that is not revoked; marks it used. Null otherwise. */
    public function principalOf(string $hash): ?int;

    /** @return list<array{id: int, principal: int, name: string, created: int, used: ?int}> keys that are not revoked */
    public function active(): array;

    public function revoke(int $id): bool;
}
