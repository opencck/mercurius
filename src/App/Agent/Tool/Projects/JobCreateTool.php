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
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\JobRepository;
use Mercurius\Memory\Domain\ContentScreen;

use function Mercurius\I18n\t;

/**
 * The agent sets itself a reminder or a check: a job by a schedule, its report comes to this chat.
 *
 * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — the agent sets reminders and checks itself; the text of a job is checked
 *       for injections
 */
final class JobCreateTool implements Tool, \OpenCCK\App\Agent\Tool\PreparesCall, \OpenCCK\App\Agent\Tool\GrantKeyed {
    public const NAME = 'job_create';
    private const PROMPT_MAX = 4000;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ConversationRepository $conversations,
        private readonly JobScheduler $scheduler,
        private readonly ContentScreen $screen,
        /** the runs, to see whether the one setting the job up had read outside data; null — not marked */
        private readonly ?\OpenCCK\Domain\Repository\RunRepository $runs = null,
        /** the projects a job may belong to; null — jobs belong to their participants only */
        private readonly ?\OpenCCK\Domain\Repository\ProjectRepository $projects = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.job_create.stavit_zadanie_po_raspisaniyu_napominani')
            . t('tool.job_create.besede_itog_pridet_v_etot')
            . t('tool.job_create.daily_chch_mm_kazhdyy_den')
            . t('tool.job_create.komandoy_hook_prompt_chto_sdelat')
            . ' ' . t('tool.job_create.project_about'), [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::PROMPT_MAX],
                'schedule' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 60],
                'watch' => ['type' => 'string', 'pattern' => '^https?://', 'maxLength' => 2048, 'description' => t('tool.job_create.watch')],
                'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => \OpenCCK\Domain\Project\Project::NAME_MAX],
            ],
            'required' => ['title', 'prompt', 'schedule'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * A job of a project runs in the name of the project's owner, so the project is found and the right to it checked
     * before the call is saved: a member who is not its owner sets the job only by the card of the agent's owner.
     *
     * spec: brief/scheduler.md#задания-проекта
     */
    public function prepare(array $args, \OpenCCK\Domain\Entity\Principal $principal, \OpenCCK\Domain\Agent\ParticipantPolicy $policy, \Amp\Cancellation $cancellation): array|string {
        $name = trim(Args::string($args, 'project') ?? '');
        unset($args['project_id'], $args['card']);
        if ($name === '') {
            return $args;
        }
        $project = $this->projects?->byName($name, \OpenCCK\Domain\Project\Project::scopeOf($principal));
        $level = $project?->levelOf($principal, $this->projects?->memberLevel($project->id, $principal->id));
        if ($project === null || $level === null) {
            return sprintf(t('tool.project.proekta_net'), $name);
        }
        if (!$level->atLeast(\OpenCCK\Domain\Project\ProjectLevel::Write)) {
            return sprintf(t('tool.project_edit.no_right'), $project->name, \OpenCCK\Domain\Project\ProjectLevel::Write->label(), $level->label());
        }
        $args['project_id'] = $project->id;
        if ($level !== \OpenCCK\Domain\Project\ProjectLevel::Owner) {
            $args['card'] = true;
        }
        return $args;
    }

    public function danger(array $args): DangerClass {
        return ($args['card'] ?? false) === true ? DangerClass::Irreversible : DangerClass::Write;
    }

    /** spec: brief/agent-loop.md#разрешения-навсегда — the owner may let a member set jobs of this project for good */
    public function grantKey(array $args): ?string {
        $project = Args::int($args, 'project_id');
        return ($args['card'] ?? false) === true && $project !== null ? 'job-create:project:' . $project : null;
    }

    public function describe(array $args): string {
        return sprintf('job_create «%s» [%s] %s — %s', Args::string($args, 'title') ?? '', Args::string($args, 'project') ?? '', Args::string($args, 'schedule') ?? '', mb_substr(Args::string($args, 'prompt') ?? '', 0, 1500));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $title = trim(Args::string($args, 'title') ?? '');
        $prompt = trim(Args::string($args, 'prompt') ?? '');
        $problem = $this->screen->problem($prompt, self::PROMPT_MAX) ?? $this->screen->problem($title, 100);
        if ($problem !== null) {
            return ToolResult::error(t('tool.job_create.tekst_zadaniya_ne_prinyat') . $problem);
        }
        try {
            $schedule = Schedule::parse(Args::string($args, 'schedule') ?? '');
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        $first = $schedule->next(new DateTimeImmutable(), $this->scheduler->zone($context->principal->id), true);
        $byEvent = (string) $schedule === Schedule::EVENT;
        if ($first === null && !$byEvent) {
            return ToolResult::error(t('tool.job_create.eto_vremya_uzhe_proshlo'));
        }
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error(t('tool.job_create.beseda_ne_naydena'));
        }
        $watch = trim(Args::string($args, 'watch') ?? '');
        if ($watch !== '' && ($byEvent || preg_match('~^https?://[^\s/]+~i', $watch) !== 1)) {
            return ToolResult::error(t('tool.job_create.watch_bad'));
        }
        // spec: brief/agent-loop.md#запуск-начатый-внешними-данными — a job set up after outside data carries the mark to its runs
        $origin = $this->runs?->find($context->runId)?->outside;
        // spec: brief/scheduler.md#задания-проекта — a named project, else the project of this chat when it is the participant's own
        $projectId = Args::int($args, 'project_id');
        $carded = ($args['card'] ?? false) === true;
        if ($projectId === null && $this->projects !== null) {
            $active = $this->projects->active($meta->channel, $meta->chatId, $meta->threadId);
            if ($active !== null && $active->visibleTo($context->principal)) {
                $projectId = $active->id;
            }
        }
        $job = $this->jobs->create($context->principal->id, $meta->channel, $meta->chatId, $title, $prompt, (string) $schedule, $first, $origin, $meta->threadId);
        if ($projectId !== null) {
            $this->jobs->setProject($job->id, $projectId);
        }
        if ($carded) {
            // the owner of the agent saw the job on the card: its runs are not marked, saved grants apply in them
            $this->jobs->setTrusted($job->id, true);
        }
        if ($watch !== '') {
            // spec: brief/scheduler.md#наблюдение-за-страницей — the job watches the address: the model only when it changed
            $this->jobs->setWatch($job->id, $watch);
        }
        if ($first === null) {
            return ToolResult::ok(sprintf(t('tool.job_create.zadanie_postavleno_po_sobytiyu_vladelets'), $job->id, $job->title, $job->id));
        }
        return ToolResult::ok(sprintf(
            t('tool.job_create.zadanie_postavleno_pervyy_zapusk'),
            $job->id,
            $job->title,
            $schedule->describe(),
            $first->setTimezone($this->scheduler->zone($context->principal->id))->format('Y-m-d H:i T'),
        ));
    }
}
