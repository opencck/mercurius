<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * What a run with nobody to ask (a job) could not do: the grants it lacked, shown to the owner as one card.
 *
 * spec: brief/scheduler.md#разрешения-задания
 */
final class GrantRequest {
    /** @param list<array{key: string, label: string}> $items */
    public function __construct(
        public readonly int $id,
        public readonly string $area,
        public readonly int $principalId,
        public readonly array $items,
        public readonly string $status,
        public readonly ?string $cardChatId = null,
        public readonly ?string $cardMessageId = null,
    ) {}
}
