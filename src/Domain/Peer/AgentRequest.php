<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * An agent that proved its key at the entrance but is not let in yet: what it called itself, its address, how it came;
 * the owner decides once.
 *
 * spec: brief/network.md#незнакомый-агент-стучится
 */
final class AgentRequest {
    public const PENDING = 'pending';
    public const ALLOWED = 'allowed';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly int $id,
        public readonly string $fingerprint,
        /** the name it claimed, cleaned */
        public readonly string $name,
        /** the address it claimed, '' — none */
        public readonly string $url,
        /** ws or http */
        public readonly string $transport,
        public readonly string $status,
    ) {}
}
