<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

/** A part of a task for a worker, as the orchestrator wrote it. */
final class WorkerTask {
    /** @param list<string> $tools */
    public function __construct(
        public readonly string $goal,
        public readonly string $context,
        public readonly string $output,
        public readonly array $tools,
        public readonly string $role,
    ) {}
}
