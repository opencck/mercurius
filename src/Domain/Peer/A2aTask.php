<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Peer;

/**
 * A task a neighbouring agent gave this agent.
 *
 * spec: BRIEF.md#сеть-агентов
 */
final class A2aTask {
    public const SUBMITTED = 'TASK_STATE_SUBMITTED';
    public const WORKING = 'TASK_STATE_WORKING';
    public const INPUT_REQUIRED = 'TASK_STATE_INPUT_REQUIRED';
    public const COMPLETED = 'TASK_STATE_COMPLETED';
    public const FAILED = 'TASK_STATE_FAILED';
    public const CANCELED = 'TASK_STATE_CANCELED';
    public const REJECTED = 'TASK_STATE_REJECTED';
    public const TERMINAL = [self::COMPLETED, self::FAILED, self::CANCELED, self::REJECTED, self::INPUT_REQUIRED];

    public function __construct(
        public readonly string $id,
        public readonly int $principalId,
        public readonly string $contextId,
        public readonly string $messageId,
        public readonly string $state,
        public readonly ?int $runId,
        public readonly ?string $result,
        public readonly int $depth = 0,
        /** @var list<string> */
        public readonly array $path = [],
        /** the run of the leader that gave the task, as it said */
        public readonly ?int $leaderRun = null,
        /** the task of the leader's own neighbour its run works on, in a chain */
        public readonly ?string $parentTask = null,
    ) {}

    public function terminal(): bool {
        return in_array($this->state, self::TERMINAL, true);
    }
}
