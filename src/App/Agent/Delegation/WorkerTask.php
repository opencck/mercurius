<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use OpenCCK\App\Agent\Model\TaskProfile;

/** A part of a task for a worker, as the orchestrator wrote it. */
final class WorkerTask {
    /** @param list<string> $tools */
    public function __construct(
        public readonly string $goal,
        public readonly string $context,
        public readonly string $output,
        public readonly array $tools,
        public readonly string $role,
        /** scores for choosing the model; null — the role decides */
        public readonly ?TaskProfile $profile = null,
    ) {}
}
