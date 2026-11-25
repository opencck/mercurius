<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Ssh\SshKey;

/** spec: brief/servers.md#ключи-ssh-в-песочнице — the account of the keys in a participant's ~/.ssh */
interface SshKeyRepository {
    /** @return list<SshKey> the keys of a holder, by name */
    public function of(int $holder): array;

    public function find(int $holder, string $name): ?SshKey;

    /** Adds the key or replaces its fingerprint and public key; a passphrase secret already named stays. */
    public function save(SshKey $key): void;

    /** Names the secret with the key's passphrase; null takes it away. False — no such key. */
    public function setPassphrase(int $holder, string $name, ?string $secret): bool;

    public function remove(int $holder, string $name): bool;
}
