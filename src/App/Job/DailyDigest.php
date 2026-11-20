<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\DigestJournal;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * The owner's morning summary: overdue tasks, deadlines today and tomorrow, jobs of the day. It is built by code
 * from the records, spends no tokens, comes once a day at the set time, not in the quiet hours, and is not sent when
 * there is nothing to say.
 *
 * spec: brief/scheduler.md#планировщик-и-проекты-1 — the personal manager: deadlines and summaries; initiative messages keep to
 *       the quiet hours; a job of a known shape is a process in code, not a plan of the model
 */
final class DailyDigest {
    private const TASKS = 30;
    private const JOBS = 10;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param string|null $time HH:MM in the owner's zone; null — no summary by itself
     * @param (Closure(): DateTimeImmutable)|null $now
     */
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly JobRepository $jobs,
        private readonly DigestJournal $journal,
        private readonly JobScheduler $scheduler,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChat,
        private readonly ?string $time,
        private readonly LoggerInterface $logger,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    private ?\Mercurius\Memory\Domain\MemoryStore $memory = null;

    /**
     * The memory whose waiting proposals the summary counts.
     *
     * spec: brief/operations.md#трассировка-и-метрики — the queue of memory proposals waiting for the owner
     */
    public function useMemory(\Mercurius\Memory\Domain\MemoryStore $memory): void {
        $this->memory = $memory;
    }

    private ?\OpenCCK\Domain\Repository\CommandAuditRepository $audit = null;

    /**
     * The command journal whose chain of hashes the summary checks: a broken one is news.
     *
     * spec: brief/servers.md#фаза-7-по-частям — the chain is checked every day
     */
    public function useAudit(\OpenCCK\Domain\Repository\CommandAuditRepository $audit): void {
        $this->audit = $audit;
    }

    /** Sends the summary of today once, when its time has come and the quiet hours are over. */
    public function tick(): void {
        if ($this->time === null) {
            return;
        }
        $now = ($this->now)();
        $local = $now->setTimezone($this->zone());
        if ($local->format('H:i') < $this->time || $this->scheduler->isQuiet($now) || !$this->journal->claim($local->format('Y-m-d'))) {
            return;
        }
        $text = $this->text($now);
        if ($text !== null && !$this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChat), SecretMasker::mask($text)))->success) {
            $this->logger->warning('Daily summary was not delivered');
        }
    }

    /** The summary as of $now; null when there is nothing to say. */
    public function text(DateTimeImmutable $now): ?string {
        $zone = $this->zone();
        $local = $now->setTimezone($zone);
        $endToday = $local->setTime(23, 59, 59);
        $endTomorrow = $endToday->modify('+1 day');
        $groups = [t('job.daily_digest.prosrocheno') => [], t('job.daily_digest.segodnya') => [], t('job.daily_digest.zavtra') => []];
        $names = [];
        foreach ($this->projects->dueTasks($endTomorrow, self::TASKS) as $task) {
            $due = $task->dueAt;
            if ($due === null) {
                continue;
            }
            if (!isset($names[$task->projectId])) {
                $project = $this->projects->find($task->projectId);
                $names[$task->projectId] = $project === null ? '?' : $project->name;
            }
            $group = $due < $now ? t('job.daily_digest.prosrocheno') : ($due <= $endToday ? t('job.daily_digest.segodnya') : t('job.daily_digest.zavtra'));
            $groups[$group][] = self::task($task, $names[$task->projectId], $due->setTimezone($zone));
        }
        $jobs = array_map(
            static fn(Job $j): string => sprintf('%s — #%d «%s»', $j->nextRunAt?->setTimezone($zone)->format('H:i') ?? '—', $j->id, $j->title),
            $this->jobs->due($endToday, self::JOBS),
        );
        $waiting = $this->waiting($now);
        $broken = null;
        try {
            $broken = $this->audit?->verify();
        } catch (\Throwable $e) {
            $this->logger->warning('Command journal was not checked', ['error' => $e::class]);
        }
        if (array_merge(...array_values($groups)) === [] && $jobs === [] && $waiting === null && $broken === null) {
            return null;
        }
        $parts = [sprintf(t('job.daily_digest.svodka_na'), $local->format('Y-m-d'))];
        foreach ($groups as $title => $lines) {
            if ($lines !== []) {
                $parts[] = $title . ":\n" . implode("\n", $lines);
            }
        }
        if ($jobs !== []) {
            $parts[] = t('job.daily_digest.zadaniya_segodnya') . implode("\n", $jobs);
        }
        if ($waiting !== null) {
            $parts[] = $waiting;
        }
        if ($broken !== null) {
            $parts[] = sprintf(t('job.daily_digest.audit_broken'), $broken);
        }
        return implode("\n\n", $parts);
    }

    /** "memory: N wait for a decision, the oldest D days"; null — none wait or no memory. */
    private function waiting(DateTimeImmutable $now): ?string {
        if ($this->memory === null) {
            return null;
        }
        try {
            $pending = $this->memory->pending();
        } catch (\Throwable $e) {
            $this->logger->warning('Memory proposals were not counted', ['error' => $e::class]);
            return null;
        }
        if ($pending === []) {
            return null;
        }
        $oldest = min(array_map(static fn(\Mercurius\Memory\Domain\Proposal $p): int => $p->createdAt ?? $now->getTimestamp(), $pending));
        return sprintf(t('job.daily_digest.memory_waiting'), count($pending), intdiv(max(0, $now->getTimestamp() - $oldest), 86400));
    }

    private function zone(): DateTimeZone {
        return $this->scheduler->zone();
    }

    private static function task(ProjectTask $task, string $project, DateTimeImmutable $due): string {
        return sprintf('• %s — #%d %s (%s)', $due->format('d.m H:i'), $task->id, $task->title, $project);
    }
}
