<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * The project a chat works on, for the prompt of a new conversation: its card, the relation of whoever writes, its
 * open tasks — within a budget of its own. The chat was bound to the project by the owner, so its audience may see the
 * card; a visitor gets nothing.
 *
 * spec: brief/memory.md#проекты-и-отношение-участника-к-проекту — the active project's card and relation
 *       in context, a change of the active project is a boundary of the snapshot
 */
final class ProjectContext {
    public const BUDGET = 2500;

    /** @var (\Closure(int): list<string>)|null the lines of the jobs of a project */
    private ?\Closure $jobs = null;

    public function __construct(private readonly ProjectRepository $projects) {}

    /**
     * spec: brief/scheduler.md#задания-проекта — a project shows its jobs, so "the task that collects news" is found
     *       and changed instead of set anew
     *
     * @param \Closure(int): list<string> $jobs by the id of the project
     */
    public function useJobs(\Closure $jobs): void {
        $this->jobs = $jobs;
    }

    /** The folder of the project in the sandbox and its jobs by a schedule. */
    public function extras(Project $project): string {
        $lines = $this->jobs === null ? [] : ($this->jobs)($project->id);
        return sprintf(t('agent.project_context.folder'), $project->id)
            . ($lines === [] ? '' : "\n\n" . t('agent.project_context.jobs') . implode("\n", $lines));
    }

    /** The project a chat or forum topic works on; null when none. */
    public function active(string $channel, string $chatId, string $threadId = ''): ?Project {
        return $this->projects->active($channel, $chatId, $threadId);
    }

    public function block(Principal $principal, string $channel, string $chatId, string $threadId = ''): string {
        if (!$principal->role->may(Power::Projects)) {
            return '';
        }
        $project = $this->projects->active($channel, $chatId, $threadId);
        // spec: brief/participants.md#доступ-к-проектам-и-передача — the card goes to the owner of the project and to its members
        if ($project !== null && $project->levelOf($principal, $this->projects->memberLevel($project->id, $principal->id)) === null) {
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
        $parts[] = $this->extras($project);
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

}
