<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * A background delegation: workers of one delegate_task call that outlive the orchestrator's run and a restart.
 *
 * spec: BRIEF.md#часть-3--фоновое-делегирование
 */
final class Delegation {
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const STOPPED = 'stopped';

    public function __construct(
        public readonly int $id,
        public readonly int $conversationId,
        /** the orchestrator's run: the workers' tokens were reserved from its budget */
        public readonly int $runId,
        public readonly int $principalId,
        public readonly string $callId,
        public readonly int $reserved,
        /** running, done, stopped */
        public readonly string $status,
        /** the workers' report, once done */
        public readonly ?string $report = null,
        public readonly bool $delivered = false,
        /** seconds to the deadline of the whole delegation; negative once it passed */
        public readonly int $secondsLeft = 0,
        /** /stop came: after a restart the delegation is closed, not continued */
        public readonly bool $stopRequested = false,
    ) {}
}
