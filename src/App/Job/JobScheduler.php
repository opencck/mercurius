<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use Amp\Future;
use Amp\NullCancellation;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Llm\Role;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\JobRuns;
use OpenCCK\App\Agent\PromptBuilder;
use OpenCCK\App\Agent\SilentObserver;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

use function Mercurius\I18n\t;

/**
 * Runs the jobs that are due: the next run is moved first, so a crash does not repeat it; each run is a fresh
 * conversation that cannot set new jobs, knows its number and the last result, and asks the owner by a card for an
 * irreversible step (a saved grant needs no card); the
 * scheduler, not the agent, delivers the result — after the quiet hours if it came during them. A job run spends at most
 * $tokenBudget tokens; a job by event runs on a webhook with the event given as data.
 *
 * spec: BRIEF.md#планировщик-и-проекты
 * ref: references/hermes-agent/cron/jobs.py@e96221fa:L2642-L2676 (advance_next_runs) — the next run advanced before the
 *      run; references/hermes-agent/cron/scheduler.py@e96221fa:L2540 (run_job) — a fresh session per job, delivery by the
 *      scheduler; here missed runs during a downtime catch up once and a job run has no irreversible steps
 */
final class JobScheduler implements JobRuns {
    public const JOB_TOOLS = ['job_create', 'job_manage'];
    /**
     * The only tools of the self-review job: it reads traces, spend, history and skills and proposes changes.
     *
     * spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the self-review has a white list of tools
     */
    public const SELF_REVIEW_TOOLS = ['trace_review', 'spending', 'self_info', 'history_search', 'experience_search', 'memory_search', 'skill_view', 'improvement_propose'];
    /** The answer of a run when there is nothing to tell: the result is kept, nothing is delivered. */
    public const SILENT = '[SILENT]';
    public const CHANNEL = 'job';
    private const BATCH = 5;
    private const REPORT_MAX = 3500;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param array{int, int}|null $quiet quiet hours [from, to) in the owner's zone; null — none
     * @param (Closure(): DateTimeImmutable)|null $now
     * @param positive-int $tokenBudget tokens per job run, below the owner's run budget
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly AgentLoop $loop,
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
        private readonly ToolRegistry $registry,
        private readonly Channel $channel,
        private readonly string $model,
        private readonly DateTimeZone $zone,
        private readonly ?array $quiet,
        private readonly LoggerInterface $logger,
        ?Closure $now = null,
        private readonly int $tokenBudget = 100_000,
        /** @var (Closure(string): string)|null the text an address gives now (through the guard of internal addresses) */
        private readonly ?Closure $fetch = null,
        /** the daily cap of reports the owner did not ask for right now; null — no cap */
        private readonly ?ProactiveBudget $budget = null,
        /** a participant's own time zone and quiet hours; null — the owner's for everyone */
        private readonly ?ParticipantClock $clock = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /** The zone a participant's jobs run by: their own, else the owner's (spec: BRIEF.md#несколько-пользователей). */
    public function zone(?int $principalId = null): DateTimeZone {
        return $this->clock === null || $principalId === null ? $this->zone : $this->clock->zone($principalId);
    }

    /** One pass: held reports out of the quiet hours, then the due jobs, each in its own fiber. */
    public function tick(bool $wait = false): void {
        $now = ($this->now)();
        foreach ($this->jobs->pendingReports() as $job) {
            if (!$this->isQuiet($now, $job->principalId)) {
                if ($this->budget !== null && !$this->budget->take()) {
                    break;
                }
                $failure = $this->deliver($job, (string) $job->pendingReport);
                if ($failure === null || $failure['permanent']) {
                    $this->jobs->clearPending($job->id);
                }
                if ($failure !== null && $failure['permanent']) {
                    $this->jobs->noteResult($job->id, sprintf(t('job.job_scheduler.delivery_refused'), $failure['reason']));
                }
            }
        }
        $futures = [];
        foreach ($this->jobs->due($now, self::BATCH) as $job) {
            try {
                $next = Schedule::parse($job->schedule)->next($now, $this->zone($job->principalId));
            } catch (Throwable) {
                $this->jobs->setStatus($job->id, Job::PAUSED);
                continue;
            }
            if (!$this->jobs->claim($job->id, $now, $next)) {
                continue;
            }
            $futures[] = async(fn() => $this->run($job));
        }
        if ($wait) {
            foreach ($futures as $future) {
                $future->await();
            }
        }
    }

    public function isQuiet(DateTimeImmutable $at, ?int $principalId = null): bool {
        $quiet = $this->clock === null || $principalId === null ? $this->quiet : $this->clock->quiet($principalId);
        if ($quiet === null) {
            return false;
        }
        [$from, $to] = $quiet;
        $hour = (int) $at->setTimezone($this->zone($principalId))->format('G');
        return $from > $to ? ($hour >= $from || $hour < $to) : ($hour >= $from && $hour < $to);
    }

    /**
     * Runs a job by event that the caller has claimed; the event is data, not instructions.
     *
     * @return Future<null>
     */
    public function runEvent(Job $job, string $event): Future {
        return async(fn() => $this->run($job, $event));
    }

    /** A run of a job: its conversation is on the channel "job". */
    public function owns(Run $run): bool {
        return $this->conversations->meta($run->conversationId)?->channel === self::CHANNEL;
    }

    /**
     * Goes on with a job run: after the owner's decision ($decided) or after a restart.
     *
     * spec: BRIEF.md#решения-владельца-2026-11-17 — an action that needs approval sends the card, a saved grant lets
     *       it run without one
     */
    public function continueRun(Run $run, bool $decided): void {
        $meta = $this->conversations->meta($run->conversationId);
        if ($meta === null || preg_match('~^job-(\d+)-(\d+)$~', $meta->chatId, $m) !== 1) {
            return;
        }
        $job = $this->jobs->find((int) $m[1]);
        $principal = $this->principals->find($run->principalId);
        if ($job === null || $principal === null) {
            $this->logger->warning('Job run cannot go on: the job or its owner is gone', ['run' => $run->id]);
            return;
        }
        if ($decided) {
            $run = $this->runs->transition($run->id, RunStatus::WaitingApproval, RunStatus::Running);
            if ($run === null) {
                return;
            }
        }
        $number = (int) $m[2];
        async(function () use ($job, $principal, $run, $number): void {
            try {
                $text = $this->complete($job, $number, $this->loop->run($run, $this->policy($principal, $job), $principal, new SilentObserver(), new NullCancellation()));
            } catch (Throwable $e) {
                $this->logger->error('Job run failed', ['job' => $job->id, 'error' => $e::class]);
                $text = t('job.job_scheduler.zapusk_ne_udalsya_vnutrennyaya_oshibka');
            }
            $this->report($job, $number, $text, $job->byEvent());
        });
    }

    private function run(Job $job, ?string $event = null): void {
        $number = $job->runs + 1;
        $source = 'webhook';
        // spec: BRIEF.md#планировщик-и-проекты — a watched address: the model only when what it gives changed
        // ref: references/hermes-agent/cron/scheduler.py@e96221fa — hermes runs a pre-check script and skips the agent when
        //      it says nothing changed; here the check is the fingerprint of the page, kept in the job
        if ($event === null && $job->watchUrl !== null && $this->fetch !== null) {
            try {
                $page = ($this->fetch)($job->watchUrl);
            } catch (Throwable $e) {
                $this->report($job, $number, sprintf(t('job.job_scheduler.watch_failed'), $job->watchUrl, mb_substr($e->getMessage(), 0, 200)), false);
                return;
            }
            $hash = hash('sha256', $page);
            if ($hash === $job->watchHash) {
                $this->jobs->finish($job->id, $job->lastResult ?? '', null);
                return;
            }
            $this->jobs->setWatchHash($job->id, $hash);
            $event = mb_substr($page, 0, 20_000);
            $source = 'watch:' . $job->watchUrl;
        }
        try {
            $text = $this->execute($job, $number, $event, $source);
        } catch (Throwable $e) {
            $this->logger->error('Job run failed', ['job' => $job->id, 'error' => $e::class]);
            $text = t('job.job_scheduler.zapusk_ne_udalsya_vnutrennyaya_oshibka');
        }
        $this->report($job, $number, $text, $event !== null && $source === 'webhook');
    }

    /** @param string|null $text null — the run waits for the owner's decision */
    private function report(Job $job, int $number, ?string $text, bool $byEvent): void {
        if ($text === null) {
            // The card went to the owner; the run goes on after the decision and reports then.
            $this->jobs->finish($job->id, t('job.job_scheduler.zhdet_podtverzhdeniya_vladeltsa'), null);
            $this->deliver($job, sprintf(t('job.job_scheduler.zadanie_zhdet_vashego_podtverzhdeniya'), $job->id, $job->title, $byEvent ? t('job.job_scheduler.sobytie') : t('job.job_scheduler.zapusk'), $number));
            return;
        }
        if (str_starts_with(ltrim($text), self::SILENT)) {
            // ref: references/hermes-agent/cron/scheduler.py@e96221fa:L543-L575 — [SILENT] is not delivered
            $this->jobs->finish($job->id, mb_substr($text, 0, 2000), null);
            return;
        }
        $report = sprintf(t('job.job_scheduler.report'), $byEvent ? '⚡' : '⏰', $job->id, $job->title, $byEvent ? t('job.job_scheduler.sobytie') : t('job.job_scheduler.zapusk'), $number, mb_substr($text, 0, self::REPORT_MAX));
        // spec: BRIEF.md#планировщик-и-проекты — quiet hours and the daily cap of initiative messages
        $held = $this->isQuiet(($this->now)(), $job->principalId) || ($this->budget !== null && !$this->budget->take());
        $this->jobs->finish($job->id, mb_substr($text, 0, 2000), $held ? $report : null);
        if ($held) {
            return;
        }
        $failure = $this->deliver($job, $report);
        if ($failure === null) {
            return;
        }
        if ($failure['permanent']) {
            $this->jobs->noteResult($job->id, sprintf(t('job.job_scheduler.delivery_refused'), $failure['reason']));
            return;
        }
        $this->jobs->holdReport($job->id, $report . "\n\n" . sprintf(t('job.job_scheduler.delivered_late'), $failure['reason']));
    }

    private ?\OpenCCK\App\Skill\SkillLibrary $skills = null;
    /** @var (\Closure(string): ?string)|null the model of a role; null — the role has none */
    private ?\Closure $modelOf = null;

    /**
     * spec: BRIEF.md#планировщик-и-проекты — a job marked cheap runs on the model of the cheap role
     *
     * @param \Closure(string): ?string $modelOf
     */
    public function useModels(\Closure $modelOf): void {
        $this->modelOf = $modelOf;
    }

    /** @var (\Closure(Principal, Job): string)|null the memory core for a job's run; '' where it must not go */
    private ?\Closure $core = null;

    /**
     * spec: BRIEF.md#контекст-по-месту-и-аудитории — a job run knows its owner's memory core only when its report goes
     *       to the owner's private chat
     *
     * @param \Closure(Principal, Job): string $core
     */
    public function useCore(\Closure $core): void {
        $this->core = $core;
    }

    /** spec: BRIEF.md#скиллы-и-mcp — the index of skills goes to the runs of jobs too */
    public function useSkills(\OpenCCK\App\Skill\SkillLibrary $skills): void {
        $this->skills = $skills;
    }

    /** A job cannot set jobs; irreversible steps ask the owner by a card or go by a saved grant. */
    private function policy(Principal $principal, Job $job): ParticipantPolicy {
        $owner = $this->policies->forPrincipal($principal);
        $tools = array_values(array_diff($this->registry->names($owner), self::JOB_TOOLS));
        if ($job->title === 'self-review') {
            $tools = array_values(array_intersect($tools, self::SELF_REVIEW_TOOLS));
        }
        $limits = $owner->run->min(new RunLimits($owner->run->maxTurns, $this->tokenBudget, $owner->run->runTimeout, $owner->run->toolTimeout));
        return new ParticipantPolicy($tools, $limits, $owner->sandbox, $owner->mayRequestApproval, false, $owner->irreversible);
    }

    private function execute(Job $job, int $number, ?string $event, string $source = 'webhook'): ?string {
        $principal = $this->principals->find($job->principalId) ?? throw new \RuntimeException('principal is gone');
        $policy = $this->policy($principal, $job);
        $conversation = $this->conversations->openConversation($principal->id, self::CHANNEL, sprintf('job-%d-%d', $job->id, $number));
        $model = $job->modelRole !== '' && $this->modelOf !== null ? (($this->modelOf)($job->modelRole) ?? $this->model) : $this->model;
        $this->conversations->setMeta($conversation, PromptBuilder::system($principal, ($this->now)(), $this->core === null ? '' : ($this->core)($principal, $job), skills: $this->skills?->index($principal, null) ?? ''), $model);
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — an event, a watched page or a job set up after outside data
        //       marks the run: no saved grant applies in it, unless the owner trusts the source of the job
        $outside = $job->trusted ? null : implode(', ', array_filter([$event === null ? null : $source, $job->origin]));
        $run = $this->runs->start($conversation, $principal->id, $model, $policy->run, null, $outside === '' ? null : $outside) ?? throw new \RuntimeException('run did not start');
        $this->transcript->appendUser($conversation, $run->id, sprintf(
            t('job.job_scheduler.zadanie_zapusk_zadanie_sdelay_ego'),
            $event === null || $source !== 'webhook' ? t('job.job_scheduler.po_raspisaniyu') : t('job.job_scheduler.po_sobytiyu'),
            $job->title,
            $number,
            ($this->now)()->setTimezone($this->zone($job->principalId))->format('Y-m-d H:i T'),
            $job->lastResult === null ? '' : t('job.job_scheduler.itog_proshlogo_zapuska') . $job->lastResult,
            $job->prompt,
            $event === null ? '' : t($source === 'webhook' ? 'job.job_scheduler.sobytie_prishlo_izvne_eto_dannye' : 'job.job_scheduler.watch_changed') . UntrustedWrapper::wrap($source, $event),
        ));
        return $this->complete($job, $number, $this->loop->run($run, $policy, $principal, new SilentObserver(), new NullCancellation()));
    }

    /** The final text of a run; null while it waits for the owner. */
    private function complete(Job $job, int $number, Run $final): ?string {
        if ($final->status === RunStatus::WaitingApproval) {
            return null;
        }
        $this->conversations->close(self::CHANNEL, sprintf('job-%d-%d', $job->id, $number));
        foreach (array_reverse($this->transcript->runMessages($final->id)) as $message) {
            if ($message->role === Role::Assistant && $message->toolCalls === [] && trim($message->content) !== '') {
                return trim($message->content);
            }
        }
        return t('job.job_scheduler.itoga_net') . ($final->stopReason?->label() ?? t('job.job_scheduler.zapusk_ne_zakonchen')) . ').';
    }

    /**
     * Sends a report; why it did not go, or null. A refusal of the chat (the bot was blocked, the chat is gone) is
     * permanent: sending again would not help.
     *
     * ref: references/hermes-agent/cron/delivery_outcome.py@e96221fa:L1-L13 — a failed delivery is written onto the
     *      job (last_delivery_error) instead of passing silently; here a passing failure is also sent again on the next
     *      tick, the report saying why it is late
     *
     * @return array{reason: string, permanent: bool}|null
     */
    private function deliver(Job $job, string $report): ?array {
        // spec: BRIEF.md#telegram-группы-каналы-и-темы — the report goes back into the topic the job was set in
        $result = $this->channel->send(new OutgoingMessage($job->chatId, SecretMasker::mask($report), threadId: $job->threadId === '' ? null : $job->threadId));
        if ($result->success) {
            return null;
        }
        $kind = $result->errorKind ?? \Mercurius\Messenger\SendErrorKind::Unknown;
        $reason = mb_substr(SecretMasker::mask($kind->value . ($result->error === null ? '' : ': ' . $result->error)), 0, 200);
        $permanent = $kind === \Mercurius\Messenger\SendErrorKind::Forbidden || $kind === \Mercurius\Messenger\SendErrorKind::NotFound;
        $this->logger->warning('Job report was not delivered', ['job' => $job->id, 'reason' => $reason, 'permanent' => $permanent]);
        return ['reason' => $reason, 'permanent' => $permanent];
    }
}
