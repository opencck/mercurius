<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/** One part of a background delegation and how far it got. */
final class DelegationTask {
    /**
     * @param array<string, mixed> $task the brief: goal, context, output, tools, role, profile
     * @param array<string, mixed>|null $report the part's report, once finished
     */
    public function __construct(
        public readonly int $delegationId,
        /** 1-based, as in the report */
        public readonly int $position,
        public readonly array $task,
        public readonly int $share,
        public readonly ?int $runId = null,
        public readonly ?int $retryRunId = null,
        public readonly ?array $report = null,
        /** the verifier's run, when the part is checked */
        public readonly ?int $verifyRunId = null,
    ) {}
}
