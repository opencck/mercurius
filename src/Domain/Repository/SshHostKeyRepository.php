<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Ssh\SshHostKey;

/** spec: brief/servers.md#ssh-как-предустановленный-mcp — the host keys of SSH entries */
interface SshHostKeyRepository {
    public function of(string $server, string $address): ?SshHostKey;

    public function find(int $id): ?SshHostKey;

    /** A key seen at a connection waits for the owner. */
    public function setPending(string $server, string $address, string $fingerprint): SshHostKey;

    /**
     * The owner's answer: trust the pending key, or drop it; null — the key the card showed is no longer the pending one.
     */
    public function decide(int $id, bool $trust, string $fingerprint): ?SshHostKey;

    /** The trusted key is forgotten: the next connection brings a card again. */
    public function forget(int $id): ?SshHostKey;

    /** A key trusted already (a server of the former registry). */
    public function pin(string $server, string $address, string $hostKey): void;
}
