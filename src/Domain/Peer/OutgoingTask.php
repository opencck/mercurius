<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * A task this agent gave a neighbour: polled until it ends, the result goes to the chat it came from.
 *
 * spec: brief/network.md#как-сделано-связь-двух-агентов
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
        /** the run that gave the task; a task of a neighbour's task waits for it */
        public readonly ?int $leaderRun = null,
        /** the context of the task at the neighbour: an answer to its question goes there */
        public readonly string $contextId = '',
    ) {}
}
