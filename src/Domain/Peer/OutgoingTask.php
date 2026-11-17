<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * A task this agent gave a neighbour: polled until it ends, the result goes to the chat it came from.
 *
 * spec: BRIEF.md#сеть-агентов
 */
final class OutgoingTask {
    public function __construct(
        public readonly int $id,
        public readonly string $peer,
        public readonly string $remoteId,
        public readonly int $principalId,
        public readonly string $channel,
        public readonly string $chatId,
        public readonly string $task,
        public readonly string $state,
        public readonly ?string $result,
    ) {}
}
