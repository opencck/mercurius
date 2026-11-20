<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Projects;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\TaskProfile;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * Keeps projects: the agent writes the card and the tasks itself; a new project, its goal or status, and a
 * participant's relation to it wait for the owner — those calls are irreversible, so the owner gets a card.
 *
 * spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту — the agent keeps the card, the goal and the status
 *       change with the owner's confirmation; a relation goes through the owner too and only from the participant's
 *       own words
 */
final class ProjectEditTool implements Tool, GrantKeyed {
    public const NAME = 'project_edit';
    public const ACTIONS = ['create', 'rename', 'goal', 'status', 'card', 'add_task', 'task_status', 'task_due', 'relation', 'activate'];
    /** what waits for the owner */
    public const CONFIRMED = ['create', 'goal', 'status', 'relation'];
    public const RELATION_MAX = 1000;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ConversationRepository $conversations,
        private readonly \DateTimeZone $zone = new \DateTimeZone('UTC'),
        /** in a forum a project gets a topic; null — no forum topics */
        private readonly ?\OpenCCK\App\Service\ForumTopics $forum = null,
    ) {}

    /**
     * A deadline in the owner's zone: a date alone means the end of that day.
     *
     * spec: brief/scheduler.md#планировщик-и-проекты-1 — deadlines for the daily summary
     */
    public static function due(string $text, \DateTimeZone $zone): ?\DateTimeImmutable {
        $text = trim($text);
        try {
            if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $text) === 1) {
                return (new \DateTimeImmutable($text, $zone))->setTime(23, 59);
            }
            return preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}~', $text) === 1 ? new \DateTimeImmutable($text, $zone) : null;
        } catch (\Exception) {
            return null;
        }
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.project_edit.vedet_proekty_project_imya_proekta')
            . t('tool.project_edit.proekt_text_tsel_goal_novaya')
            . t('tool.project_edit.repozitorii_servery_stek_prinyatye_reshe')
            . t('tool.project_edit.1_10_po_osyam_esli')
            . t('tool.project_edit.open_doing_done_dropped_dlya')
            . t('tool.project_edit.relation_otnoshenie_sobesednika_k_proekt')
            . t('tool.project_edit.proekt_proektom_etogo_chata_create')
            . ' ' . t('tool.project_edit.rename_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'text' => ['type' => 'string', 'maxLength' => Project::CARD_MAX],
                'status' => ['type' => 'string', 'enum' => array_values(array_unique([...Project::STATUSES, ...ProjectTask::STATUSES]))],
                'task_id' => ['type' => 'integer', 'minimum' => 1],
                'parent_id' => ['type' => 'integer', 'minimum' => 1],
                'profile' => ['type' => 'object'],
                'due' => ['type' => 'string', 'maxLength' => 40],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action'), self::CONFIRMED, true) ? DangerClass::Irreversible : DangerClass::Write;
    }

    /**
     * spec: brief/agent-loop.md#контекст — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    public function grantKey(array $args): ?string {
        $action = Args::string($args, 'action') ?? '';
        if (!in_array($action, self::CONFIRMED, true)) {
            return null;
        }
        $project = mb_strtolower(trim(Args::string($args, 'project') ?? ''));
        return $action === 'create' || $project === '' ? 'project:' . $action : sprintf('project:%s:%s', $action, $project);
    }

    public function describe(array $args): string {
        return sprintf('project_edit %s «%s» %s', Args::string($args, 'action') ?? '', Args::string($args, 'project') ?? '', mb_substr(Args::string($args, 'status') ?? Args::string($args, 'text') ?? '', 0, 200));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        $text = trim(Args::string($args, 'text') ?? '');
        if ($text !== '' && SecretMasker::mask($text) !== $text) {
            return ToolResult::error(t('tool.project_edit.v_proekte_ne_hranyat_sekrety'));
        }
        if ($action === 'create') {
            $name = trim(Args::string($args, 'project') ?? '');
            if ($name === '') {
                return ToolResult::error(t('tool.project_edit.nuzhno_imya_proekta_project'));
            }
            try {
                $project = $this->projects->create($name, mb_substr($text, 0, Project::GOAL_MAX), Project::scopeOf($context->principal));
            } catch (DomainException $e) {
                return ToolResult::error($e->getMessage());
            }
            $meta = $this->conversations->meta($context->conversationId);
            // spec: brief/channels.md#форум-темы--проекты — a new project in a forum gets its own topic
            $topic = $meta === null ? '' : ($this->forum?->created($project, $meta) ?? '');
            return ToolResult::ok(trim(sprintf(t('tool.project_edit.proekt_zaveden'), $project->name, $topic)));
        }
        $project = $this->project($args, $context);
        if ($project === null) {
            return ToolResult::error(t('tool.project_edit.proekt_ne_nayden_nazovi_ego'));
        }
        return match ($action) {
            'goal' => $this->update($project, $text === '' ? null : mb_substr($text, 0, Project::GOAL_MAX), null, null, t('tool.project_edit.tsel')),
            'status' => in_array(Args::string($args, 'status'), Project::STATUSES, true)
                ? $this->update($project, null, Args::string($args, 'status'), null, t('tool.project_edit.status'))
                : ToolResult::error(t('tool.project_edit.status_proekta_active_paused_ili')),
            'card' => $this->update($project, null, null, $text, t('tool.project_edit.kartochka')),
            'add_task' => $this->addTask($project, $text, Args::int($args, 'parent_id'), $args),
            'task_status' => $this->taskStatus($project, Args::int($args, 'task_id'), Args::string($args, 'status'), $text),
            'task_due' => $this->taskDue($project, Args::int($args, 'task_id'), Args::string($args, 'due') ?? ''),
            'relation' => $this->relation($project, $context, $text),
            'activate' => $this->activate($project, $context),
            'rename' => $this->rename($project, trim(Args::string($args, 'name') ?? '')),
            default => ToolResult::error(t('tool.project_edit.neizvestnoe_deystvie') . $action),
        };
    }

    /** @param array<string, mixed> $args */
    private function project(array $args, ToolContext $context): ?Project {
        $name = Args::string($args, 'project');
        if ($name !== null) {
            return $this->projects->byName($name, Project::scopeOf($context->principal));
        }
        $meta = $this->conversations->meta($context->conversationId);
        $active = $meta === null ? null : $this->projects->active($meta->channel, $meta->chatId, $meta->threadId);
        // spec: brief/participants.md#несколько-пользователей — a participant works only with their own projects
        return $active !== null && $active->visibleTo($context->principal) ? $active : null;
    }

    /** spec: brief/channels.md#форум-темы--проекты — a new name for the project and its forum topics */
    private function rename(Project $project, string $name): ToolResult {
        if ($name === '' || mb_strlen($name) > Project::NAME_MAX) {
            return ToolResult::error(sprintf(t('tool.project_edit.rename_needs_name'), Project::NAME_MAX));
        }
        try {
            $this->projects->rename($project->id, $name);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        $this->forum?->renamed($project->id, $name);
        return ToolResult::ok(sprintf(t('tool.project_edit.renamed'), $project->name, $name));
    }

    private function update(Project $project, ?string $goal, ?string $status, ?string $card, string $what): ToolResult {
        $this->projects->update($project->id, $goal, $status, $card);
        if ($status !== null) {
            $this->forum?->statusChanged($project, $status);
        }
        return ToolResult::ok(sprintf(t('tool.project_edit.proekta_obnovlena'), $what, $project->name));
    }

    /** @param array<string, mixed> $args */
    private function addTask(Project $project, string $title, ?int $parentId, array $args): ToolResult {
        if ($title === '' || mb_strlen($title) > ProjectTask::TITLE_MAX) {
            return ToolResult::error(sprintf(t('tool.project_edit.zadache_nuzhna_formulirovka_do_simvolov'), ProjectTask::TITLE_MAX));
        }
        if ($parentId !== null && $this->projects->task($parentId)?->projectId !== $project->id) {
            return ToolResult::error(sprintf(t('tool.project_edit.zadachi_v_proekte_net'), $parentId, $project->name));
        }
        $profile = isset($args['profile']) && is_array($args['profile']) ? TaskProfile::fromArray($args['profile'])->nonTrivial() : [];
        $dueText = Args::string($args, 'due') ?? '';
        $due = $dueText === '' ? null : self::due($dueText, $this->zone);
        if ($dueText !== '' && $due === null) {
            return ToolResult::error(t('tool.project_edit.srok_gggg_mm_dd_ili'));
        }
        $task = $this->projects->addTask($project->id, $title, $parentId, $profile, $due);
        return ToolResult::ok(sprintf(t('tool.project_edit.zadacha_dobavlena_v'), $task->id, $project->name, $due === null ? '' : t('tool.project_edit.srok') . $this->when($due)));
    }

    private function taskDue(Project $project, ?int $taskId, string $dueText): ToolResult {
        $task = $taskId === null ? null : $this->projects->task($taskId);
        if ($task === null || $task->projectId !== $project->id) {
            return ToolResult::error(sprintf(t('tool.project_edit.zadachi_v_proekte_net'), (int) $taskId, $project->name));
        }
        $due = trim($dueText) === '' ? null : self::due($dueText, $this->zone);
        if (trim($dueText) !== '' && $due === null) {
            return ToolResult::error(t('tool.project_edit.srok_gggg_mm_dd_ili'));
        }
        $this->projects->setTaskDue($task->id, $due);
        return ToolResult::ok($due === null ? sprintf(t('tool.project_edit.srok_zadachi_snyat'), $task->id) : sprintf(t('tool.project_edit.srok_zadachi'), $task->id, $this->when($due)));
    }

    private function when(\DateTimeImmutable $at): string {
        return $at->setTimezone($this->zone)->format('Y-m-d H:i T');
    }

    private function taskStatus(Project $project, ?int $taskId, ?string $status, string $result): ToolResult {
        $task = $taskId === null ? null : $this->projects->task($taskId);
        if ($task === null || $task->projectId !== $project->id) {
            return ToolResult::error(sprintf(t('tool.project_edit.zadachi_v_proekte_net'), (int) $taskId, $project->name));
        }
        if (!in_array($status, ProjectTask::STATUSES, true)) {
            return ToolResult::error(t('tool.project_edit.status_zadachi_open_doing_done'));
        }
        $closing = $status === ProjectTask::DONE || $status === ProjectTask::DROPPED;
        if ($closing && $result === '') {
            return ToolResult::error(t('tool.project_edit.zakrytoy_zadache_nuzhen_itog_text'));
        }
        $this->projects->setTaskStatus($task->id, $status, $result === '' ? null : mb_substr($result, 0, ProjectTask::RESULT_MAX));
        return ToolResult::ok(sprintf(t('tool.project_edit.zadacha'), $task->id, $status));
    }

    private function relation(Project $project, ToolContext $context, string $text): ToolResult {
        if ($text === '' || mb_strlen($text) > self::RELATION_MAX) {
            return ToolResult::error(sprintf(t('tool.project_edit.otnoshenie_slovami_sobesednika_do_simvol'), self::RELATION_MAX));
        }
        $this->projects->setRelation($project->id, $context->principal->id, $text);
        return ToolResult::ok(sprintf(t('tool.project_edit.otnoshenie_k_proektu_zapisano'), $project->name));
    }

    private function activate(Project $project, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error(t('tool.project_edit.beseda_ne_naydena'));
        }
        $this->projects->activate($meta->channel, $meta->chatId, $project->id, $meta->threadId);
        return ToolResult::ok(sprintf(t('tool.project_edit.proekt_etogo_chata_kartochka_pridet'), $project->name));
    }
}
