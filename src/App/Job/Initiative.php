<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\JobRepository;

use function Mercurius\I18n\t;

/**
 * The agent's own initiative: a built-in job of the owner that, on the schedule of `INITIATIVE_SCHEDULE`, looks at the
 * projects, deadlines and what it knows and writes to the owner only when there is something worth saying — otherwise
 * it answers `[SILENT]` and nothing is sent. Reports go through the scheduler: quiet hours and the daily cap apply.
 *
 * spec: brief/scheduler.md#планировщик-и-проекты-1 — initiative messages with a set frequency and quiet hours
 * ref: references/hermes-agent/gateway/turn_context.py@e96221fa:L20-L22 — hermes runs scheduled heartbeats as
 *      proactive work that stays quiet when it has nothing to say; here it is an ordinary job with [SILENT]
 */
final class Initiative {
    public const TITLE = 'initiative';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param (Closure(): DateTimeImmutable)|null $now
     * @param string $title the job's title, by which it is found again ('self-review' for the review of traces)
     * @param string $promptKey the text of its task in the catalog of texts
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly DateTimeZone $zone,
        ?Closure $now = null,
        private readonly string $title = self::TITLE,
        private readonly string $promptKey = 'job.initiative.prompt',
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Brings the owner's initiative job in line with the setting: '' pauses it; a schedule creates or reschedules it.
     * False when the schedule cannot be read; nothing is changed then.
     */
    public function sync(Principal $owner, string $channel, string $chatId, string $schedule): bool {
        $schedule = trim($schedule);
        $job = null;
        foreach ($this->jobs->list($owner->id) as $candidate) {
            if ($candidate->title === $this->title && $candidate->status !== Job::DONE) {
                $job = $candidate;
                break;
            }
        }
        if ($schedule === '') {
            if ($job !== null && $job->status === Job::ACTIVE) {
                $this->jobs->setStatus($job->id, Job::PAUSED);
            }
            return true;
        }
        try {
            $parsed = Schedule::parse($schedule);
        } catch (InvalidArgumentException) {
            return false;
        }
        if ($parsed->kind === Schedule::EVENT || $parsed->kind === 'once') {
            return false;
        }
        $next = $parsed->next(($this->now)(), $this->zone, true);
        if ($job === null) {
            $this->jobs->create($owner->id, $channel, $chatId, $this->title, t($this->promptKey), (string) $parsed, $next);
            return true;
        }
        if ($job->schedule !== (string) $parsed || $job->status !== Job::ACTIVE) {
            $this->jobs->setSchedule($job->id, (string) $parsed, $next);
            $this->jobs->setStatus($job->id, Job::ACTIVE);
        }
        return true;
    }
}
