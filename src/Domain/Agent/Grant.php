<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * A saved permission: this participant's action with this key no longer asks.
 *
 * spec: brief/agent-loop.md#разрешения-навсегда
 */
final class Grant {
    public function __construct(
        public readonly int $id,
        public readonly int $principalId,
        public readonly string $key,
        /** the conversation the grant lasts for; null — for good */
        public readonly ?int $conversationId = null,
        /** applies in a run that read outside data too */
        public readonly bool $outside = false,
        /** how many calls ran by it */
        public readonly int $uses = 0,
    ) {}
}
