<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Projects;

use DateTimeImmutable;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Job\HookFilter;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\JobRepository;

use function Mercurius\I18n\t;

/**
 * The jobs of the caller: the list, cancelling, a pause, resuming and a run now.
 *
 * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов
 * ref: references/hermes-agent/cron/jobs.py@e96221fa:L2141-L2217 (pause_job, resume_job, trigger_job) — pause, resume and
 *      run now; here resuming counts the next run from now, so a slot missed while paused does not run at once
 */
final class JobManageTool implements Tool {
    public const NAME = 'job_manage';
    public const ACTIONS = ['list', 'edit', 'cancel', 'pause', 'resume', 'run_now', 'trust', 'distrust', 'filter', 'model', 'to_project'];
    private const PROMPT_MAX = 4000;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobScheduler $scheduler,
        /** the projects jobs belong to; null — jobs belong to their participants only */
        private readonly ?\OpenCCK\Domain\Repository\ProjectRepository $projects = null,
        /** the check of a job's text, as on creation; null — no editing */
        private readonly ?\Mercurius\Memory\Domain\ContentScreen $screen = null,
        /** the runs, to see whether the one editing the job had read outside data; null — not marked */
        private readonly ?\OpenCCK\Domain\Repository\RunRepository $runs = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.job_manage.zadaniya_po_raspisaniyu_action_list')
            . t('tool.job_manage.edit_about') . ' ' . t('tool.job_manage.pause_priostanovit_resume_vozobnovit_run') . ' ' . t('tool.job_manage.filter_model') . ' ' . t('tool.job_manage.to_project_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'filter' => ['type' => 'string', 'maxLength' => 500],
                'role' => ['type' => 'string', 'enum' => ['main', 'cheap']],
                'project' => ['type' => 'string', 'maxLength' => \OpenCCK\Domain\Project\Project::NAME_MAX],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::PROMPT_MAX],
                'schedule' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 60],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        // spec: brief/agent-loop.md#запуск-начатый-внешними-данными — trusting the source of a job is the owner's, always by a card
        if (Args::string($args, 'action') === 'trust') {
            return DangerClass::Irreversible;
        }
        return in_array(Args::string($args, 'action') ?? 'list', ['list'], true) && Args::int($args, 'id') === null ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = Args::int($args, 'id');
        $action = Args::string($args, 'action') ?? ($id === null ? 'list' : 'cancel');
        if ($action === 'list') {
            $list = $this->jobs->list($context->principal->isOwner() ? null : $context->principal->id);
            /** @var list<int> $mine the projects the participant works with */
            $mine = [];
            // spec: brief/scheduler.md#задания-проекта — the jobs of a project are seen by everyone who works with it
            if (!$context->principal->isOwner() && $this->projects !== null) {
                $mine = array_map(static fn(\OpenCCK\Domain\Project\Project $p): int => $p->id, $this->projects->all(100, $context->principal->id));
                $seen = array_map(static fn(Job $j): int => $j->id, $list);
                foreach ($this->jobs->ofProjects($mine) as $job) {
                    if (!in_array($job->id, $seen, true)) {
                        $list[] = $job;
                    }
                }
            }
            $lines = array_map(fn(Job $j): string => self::line($j, $this->scheduler), $list);
            // spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — a one-off job that ran stays in the list for a day, with its outcome
            foreach ($this->jobs->finished($context->principal->isOwner() ? null : $context->principal->id, $mine, new DateTimeImmutable('-1 day')) as $job) {
                $lines[] = self::finishedLine($job);
            }
            return ToolResult::ok($lines === [] ? t('tool.job_manage.zadaniy_net') : implode("\n", $lines));
        }
        $job = $id === null ? null : $this->jobs->find($id);
        if ($job === null || !$this->manages($job, $context->principal) || ($job->status === Job::DONE && $job->runs === 0)) {
            return ToolResult::error(sprintf(t('tool.job_manage.zadaniya_net'), (int) $id));
        }
        if ($job->status === Job::DONE) {
            // a finished job is not "no such job": the answer says it ran and what came of it
            return ToolResult::ok(self::finishedLine($job));
        }
        if ($action === 'to_project') {
            return $this->toProject($job, $context, trim(Args::string($args, 'project') ?? ''));
        }
        if ($action === 'edit') {
            return $this->edit($job, $args, $context);
        }
        return match ($action) {
            'cancel' => $this->set($job, Job::DONE, t('tool.job_manage.otmeneno')),
            'pause' => $this->set($job, Job::PAUSED, t('tool.job_manage.priostanovleno')),
            'resume' => $this->resume($job),
            'run_now' => $this->runNow($job),
            'trust' => $this->trust($job, true, $context),
            'distrust' => $this->trust($job, false, $context),
            'filter' => $this->filter($job, Args::string($args, 'filter') ?? ''),
            'model' => $this->model($job, Args::string($args, 'role') ?? 'main'),
            default => ToolResult::error(t('tool.job_manage.neizvestnoe_deystvie') . $action),
        };
    }

    /** A job is managed by whoever set it, by the owner of the agent, and by those who change its project. */
    private function manages(Job $job, \OpenCCK\Domain\Entity\Principal $who): bool {
        if ($who->isOwner() || $job->principalId === $who->id) {
            return true;
        }
        $project = $job->projectId === null ? null : $this->projects?->find($job->projectId);
        return $project?->levelOf($who, $this->projects?->memberLevel($project->id, $who->id))?->atLeast(\OpenCCK\Domain\Project\ProjectLevel::Write) === true;
    }

    /**
     * Puts a job into a project (or takes it out with an empty name): from then on it runs in the name of the project's
     * owner, so only the owner of that project decides it.
     *
     * spec: brief/scheduler.md#задания-проекта
     */
    private function toProject(Job $job, ToolContext $context, string $name): ToolResult {
        if ($this->projects === null) {
            return ToolResult::error(t('tool.job_manage.neizvestnoe_deystvie') . 'to_project');
        }
        if ($name === '') {
            $this->jobs->setProject($job->id, null);
            return ToolResult::ok(sprintf(t('tool.job_manage.out_of_project'), $job->id, $job->title));
        }
        $project = $this->projects->byName($name, \OpenCCK\Domain\Project\Project::scopeOf($context->principal));
        if ($project === null || !$project->visibleTo($context->principal)) {
            return ToolResult::error(sprintf(t('tool.job_manage.project_owner_only'), $name));
        }
        $this->jobs->setProject($job->id, $project->id);
        return ToolResult::ok(sprintf(t('tool.job_manage.into_project'), $job->id, $job->title, $project->name));
    }

    private function set(Job $job, string $status, string $what): ToolResult {
        $this->jobs->setStatus($job->id, $status);
        return ToolResult::ok(sprintf(t('tool.job_manage.zadanie'), $job->id, $job->title, $what));
    }

    private function resume(Job $job): ToolResult {
        if ($job->status === Job::ACTIVE) {
            return ToolResult::ok(sprintf(t('tool.job_manage.zadanie_i_tak_idet'), $job->id, $job->title));
        }
        if (!$job->byEvent()) {
            try {
                $next = Schedule::parse($job->schedule)->next(new DateTimeImmutable(), $this->scheduler->zone($job->principalId));
            } catch (InvalidArgumentException) {
                $next = null;
            }
            if ($next === null) {
                return ToolResult::error(sprintf(t('tool.job_manage.u_zadaniya_net_sleduyuschego_zapuska'), $job->id));
            }
            $this->jobs->setNext($job->id, $next);
        }
        return $this->set($job, Job::ACTIVE, t('tool.job_manage.vozobnovleno'));
    }

    private function trust(Job $job, bool $trusted, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.job_manage.trust_owner_only'));
        }
        $this->jobs->setTrusted($job->id, $trusted);
        return ToolResult::ok(sprintf(t($trusted ? 'tool.job_manage.trusted' : 'tool.job_manage.distrusted'), $job->id, $job->title));
    }

    /** spec: brief/scheduler.md#как-сделано-задания-по-событию-и-проекты — which webhook events wake the job and how long a burst is merged */
    private function filter(Job $job, string $text): ToolResult {
        if (!$job->byEvent()) {
            return ToolResult::error(sprintf(t('tool.job_manage.filter_event_only'), $job->id));
        }
        $filter = HookFilter::parse($text);
        if (is_string($filter)) {
            return ToolResult::error($filter);
        }
        $this->jobs->setHookFilter($job->id, (string) $filter);
        return ToolResult::ok(sprintf(t('tool.job_manage.filter_set'), $job->id, $job->title, (string) $filter === '' ? '—' : (string) $filter));
    }

    /** spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — a job of simple checks runs on the cheap model */
    private function model(Job $job, string $role): ToolResult {
        $this->jobs->setModelRole($job->id, $role === 'cheap' ? 'cheap' : '');
        return ToolResult::ok(sprintf(t('tool.job_manage.model_set'), $job->id, $job->title, $role === 'cheap' ? 'cheap' : 'main'));
    }

    /**
     * A new text, title or schedule of the same job: "redo the job" changes it instead of setting a second one.
     *
     * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов
     *
     * @param array<string, mixed> $args
     */
    private function edit(Job $job, array $args, ToolContext $context): ToolResult {
        if ($this->screen === null) {
            return ToolResult::error(t('tool.job_manage.neizvestnoe_deystvie') . 'edit');
        }
        $title = trim(Args::string($args, 'title') ?? '');
        $prompt = trim(Args::string($args, 'prompt') ?? '');
        $given = trim(Args::string($args, 'schedule') ?? '');
        if ($title === '' && $prompt === '' && $given === '') {
            return ToolResult::error(t('tool.job_manage.edit_nothing'));
        }
        $problem = ($prompt === '' ? null : $this->screen->problem($prompt, self::PROMPT_MAX)) ?? ($title === '' ? null : $this->screen->problem($title, 100));
        if ($problem !== null) {
            return ToolResult::error(t('tool.job_create.tekst_zadaniya_ne_prinyat') . $problem);
        }
        if ($given !== '') {
            if ($job->byEvent()) {
                return ToolResult::error(t('tool.job_manage.zadanie_po_sobytiyu_zapuskaetsya_webhook'));
            }
            try {
                $schedule = Schedule::parse($given);
            } catch (InvalidArgumentException $e) {
                return ToolResult::error($e->getMessage());
            }
            $next = $schedule->next(new DateTimeImmutable(), $this->scheduler->zone($job->principalId), true);
            if ($next === null) {
                return ToolResult::error(t('tool.job_create.eto_vremya_uzhe_proshlo'));
            }
            $this->jobs->setSchedule($job->id, (string) $schedule, $next);
        }
        if ($title !== '' || $prompt !== '') {
            // spec: brief/agent-loop.md#запуск-начатый-внешними-данными — a text written after outside data carries the mark
            $this->jobs->rewrite($job->id, $title !== '' ? $title : $job->title, $prompt !== '' ? $prompt : $job->prompt, $this->runs?->find($context->runId)?->outside);
        }
        $edited = $this->jobs->find($job->id) ?? $job;
        return ToolResult::ok(sprintf(t('tool.job_manage.edited'), $edited->id, $edited->title, self::line($edited, $this->scheduler)));
    }

    private function runNow(Job $job): ToolResult {
        if ($job->byEvent()) {
            return ToolResult::error(t('tool.job_manage.zadanie_po_sobytiyu_zapuskaetsya_webhook'));
        }
        if ($job->status !== Job::ACTIVE) {
            return ToolResult::error(sprintf(t('tool.job_manage.zadanie_na_pauze_snachala_resume'), $job->id));
        }
        $this->scheduler->askNow($job->id);
        return ToolResult::ok(sprintf(t('tool.job_manage.zadanie_zapustitsya_v_techenie_minuty'), $job->id, $job->title));
    }

    private static function finishedLine(Job $job): string {
        return sprintf(t('tool.job_manage.finished'), $job->id, $job->title, $job->runs, mb_substr(trim(preg_replace('~\s+~u', ' ', $job->lastResult ?? '') ?? ''), 0, 600) ?: '—');
    }

    public static function line(Job $job, JobScheduler $scheduler): string {
        try {
            $when = Schedule::parse($job->schedule)->describe();
        } catch (InvalidArgumentException) {
            $when = $job->schedule;
        }
        return sprintf(
            t('tool.job_manage.sleduyuschiy_zapuskov'),
            $job->id,
            $job->title,
            $when,
            $job->status === Job::ACTIVE ? t('tool.job_manage.vklyucheno') : t('tool.job_manage.na_pauze'),
            $job->nextRunAt === null ? '—' : $job->nextRunAt->setTimezone($scheduler->zone($job->principalId))->format('Y-m-d H:i'),
            $job->runs,
        );
    }
}
