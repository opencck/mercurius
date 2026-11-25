<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Ssh;

/**
 * A key of a participant's ~/.ssh as the agent keeps it: the file name, its fingerprint and public key, and the name
 * of the secret that holds its passphrase. The private key stays in the sandbox and is never kept here.
 *
 * spec: brief/servers.md#ключи-ssh-в-песочнице
 */
final class SshKey {
    /** a key's file name in ~/.ssh: no path, no dot first */
    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,31}$~';

    public function __construct(
        public readonly int $holder,
        public readonly string $name,
        public readonly string $fingerprint,
        public readonly string $publicKey,
        public readonly ?string $passphraseSecret = null,
    ) {}
}
