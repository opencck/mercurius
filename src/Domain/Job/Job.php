<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Job;

use DateTimeImmutable;

/**
 * A scheduled job: what to do, when, for whom and where to report. A job by event runs on a signed webhook; the vault
 * secret that signs it is named in $hookSecret.
 *
 * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов
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
        /** the address the job watches: the model is called only when what it gives changed */
        public readonly ?string $watchUrl = null,
        /** the fingerprint of what the address gave last time */
        public readonly ?string $watchHash = null,
        /** the outside data the run that set the job up had read; its runs carry that mark */
        public readonly ?string $origin = null,
        /** the owner trusts the source of the job: its runs are not marked and saved grants apply */
        public readonly bool $trusted = false,
        /** the forum topic the job was set in; '' — the chat itself */
        public readonly string $threadId = '',
        /** which webhook events wake the job and how long a burst is merged (App\Job\HookFilter); '' — every event */
        public readonly string $hookFilter = '',
        /** the model role its runs use: '' — the main model, 'cheap' — for simple checks */
        public readonly string $modelRole = '',
    ) {}

    public function byEvent(): bool {
        return $this->schedule === Schedule::EVENT;
    }
}
