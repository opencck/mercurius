<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * A neighbouring agent this agent connects to: the token of its entrance is a vault secret by name, its public key is
 * pinned when it is added and checks its answers.
 *
 * spec: BRIEF.md#сеть-агентов
 */
final class Peer implements \Mercurius\Peer\PeerEndpoint {
    /** @param array<array-key, mixed>|null $card */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $secret,
        public readonly string $publicKey,
        public readonly ?array $card,
        /** the name of this agent's key it presents to the neighbour; '' — the main key */
        public readonly string $identity = '',
        /** the connection to it is kept: opened again after a drop or a restart */
        public readonly bool $keep = false,
    ) {}

    public function peerName(): string {
        return $this->name;
    }

    public function peerUrl(): string {
        return $this->url;
    }

    public function peerPublicKey(): string {
        return $this->publicKey;
    }

    public function peerCard(): ?array {
        return $this->card;
    }

    public function peerIdentity(): string {
        return $this->identity;
    }
}
