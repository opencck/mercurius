<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

/** What a worker did: a failure is a value here, not an exception. */
final class WorkerReport {
    public function __construct(
        public readonly int $index,
        public readonly ?int $runId,
        /** done, failed, limit, stopped */
        public readonly string $status,
        public readonly string $model,
        public readonly int $turns,
        public readonly int $tokens,
        public readonly string $summary,
        public readonly ?string $reason = null,
    ) {}
}
