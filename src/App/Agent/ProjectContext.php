<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * The project a chat works on, for the prompt of a new conversation: its card, the relation of whoever writes, its
 * open tasks — within a budget of its own. The chat was bound to the project by the owner, so its audience may see the
 * card; a visitor gets nothing.
 *
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — the active project's card, relation and open tasks
 *       in context, a change of the active project is a boundary of the snapshot
 */
final class ProjectContext {
    public const BUDGET = 2500;
    public const TASKS = 10;

    public function __construct(private readonly ProjectRepository $projects) {}

    /** The project a chat or forum topic works on; null when none. */
    public function active(string $channel, string $chatId, string $threadId = ''): ?Project {
        return $this->projects->active($channel, $chatId, $threadId);
    }

    public function block(Principal $principal, string $channel, string $chatId, string $threadId = ''): string {
        if ($principal->role === Role::Visitor) {
            return '';
        }
        $project = $this->projects->active($channel, $chatId, $threadId);
        if ($project !== null && !$project->visibleTo($principal)) {
            return '';
        }
        if ($project === null) {
            return '';
        }
        $parts = [sprintf(t('agent.project_context.aktivnyy_proekt'), $project->name, self::status($project->status))];
        if ($project->goal !== '') {
            $parts[] = t('agent.project_context.tsel') . $project->goal;
        }
        if ($project->card !== '') {
            $parts[] = t('agent.project_context.kartochka') . $project->card;
        }
        $relation = $this->projects->relation($project->id, $principal->id);
        if ($relation !== null) {
            $parts[] = t('agent.project_context.otnoshenie_sobesednika_k_proektu_ego') . $relation;
        }
        $tasks = $this->projects->openTasks($project->id, self::TASKS);
        if ($tasks !== []) {
            $parts[] = t('agent.project_context.otkrytye_zadachi') . implode("\n", array_map(self::task(...), $tasks));
        }
        $text = SecretMasker::mask(implode("\n\n", $parts));
        return mb_strlen($text) > self::BUDGET ? mb_substr($text, 0, self::BUDGET - 1) . '…' : $text;
    }

    public static function status(string $status): string {
        return match ($status) {
            Project::ACTIVE => t('agent.project_context.idet'),
            Project::PAUSED => t('agent.project_context.na_pauze'),
            Project::DONE => t('agent.project_context.zakryt'),
            default => $status,
        };
    }

    public static function task(ProjectTask $task): string {
        return sprintf(
            '#%d%s %s%s%s',
            $task->id,
            $task->parentId === null ? '' : sprintf(t('agent.project_context.chast'), $task->parentId),
            $task->title,
            $task->status === ProjectTask::DOING ? t('agent.project_context.v_rabote') : '',
            $task->dueAt === null ? '' : t('agent.project_context.srok') . $task->dueAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
        );
    }
}
