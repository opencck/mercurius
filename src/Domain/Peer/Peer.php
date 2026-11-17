<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * A neighbouring agent this agent connects to: the token of its entrance is a vault secret by name, its public key is
 * pinned when it is added and checks its answers.
 *
 * spec: BRIEF.md#сеть-агентов
 */
final class Peer {
    /** @param array<array-key, mixed>|null $card */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $secret,
        public readonly string $publicKey,
        public readonly ?array $card,
    ) {}
}
