<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Job;

use DateTimeImmutable;

/**
 * A scheduled job: what to do, when, for whom and where to report. A job by event runs on a signed webhook; the vault
 * secret that signs it is named in $hookSecret.
 *
 * spec: BRIEF.md#планировщик-и-проекты
 */
final class Job {
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const DONE = 'done';

    public function __construct(
        public readonly int $id,
        public readonly int $principalId,
        public readonly string $channel,
        public readonly string $chatId,
        public readonly string $title,
        public readonly string $prompt,
        public readonly string $schedule,
        public readonly ?DateTimeImmutable $nextRunAt,
        public readonly int $runs,
        public readonly ?string $lastResult,
        public readonly ?string $pendingReport,
        public readonly string $status,
        public readonly ?string $hookSecret = null,
    ) {}

    public function byEvent(): bool {
        return $this->schedule === Schedule::EVENT;
    }
}
