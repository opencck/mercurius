<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ProjectRepository;

/**
 * The owner's commands for projects: the list, the project of this chat, binding a chat to a project.
 *
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — the active project is set by the conversation or by
 *       the owner's command; a change takes effect from the next conversation
 */
final class ProjectCommands {
    private const LIST = 30;
    private const TASKS = 20;

    public function __construct(private readonly ProjectRepository $projects) {}

    public function list(string $channel, string $chatId, string $threadId = ''): string {
        $all = $this->projects->all(self::LIST);
        if ($all === []) {
            return 'Проектов пока нет. /project <имя> — завести проект и привязать к нему этот чат.';
        }
        $active = $this->projects->active($channel, $chatId, $threadId);
        return "Проекты:\n" . implode("\n", array_map(
            static fn(Project $p): string => sprintf('%s%s — %s', $active?->id === $p->id ? '▶ ' : '', $p->name, ProjectContext::status($p->status)),
            $all,
        ));
    }

    /** "/project" shows the chat's project, "/project <name>" binds the chat to it (creating it), "/project -" unbinds. */
    public function project(string $channel, string $chatId, string $argument, string $threadId = ''): string {
        $name = trim($argument);
        if ($name === '') {
            $project = $this->projects->active($channel, $chatId, $threadId);
            if ($project === null) {
                return 'У этого чата нет проекта. /project <имя> — привязать.';
            }
            $tasks = $this->projects->openTasks($project->id, self::TASKS);
            return SecretMasker::mask(implode("\n\n", array_filter([
                sprintf('Проект чата: %s — %s', $project->name, ProjectContext::status($project->status)),
                $project->goal === '' ? '' : 'Цель: ' . $project->goal,
                $project->card === '' ? '' : "Карточка:\n" . $project->card,
                $tasks === [] ? 'Открытых задач нет.' : "Открытые задачи:\n" . implode("\n", array_map(ProjectContext::task(...), $tasks)),
            ], static fn(string $part): bool => $part !== '')));
        }
        if ($name === '-') {
            $this->projects->activate($channel, $chatId, null, $threadId);
            return 'Чат отвязан от проекта; со следующей беседы (/new) карточки в контексте не будет.';
        }
        if (mb_strlen($name) > Project::NAME_MAX) {
            return sprintf('Имя проекта — до %d символов.', Project::NAME_MAX);
        }
        $project = $this->projects->byName($name);
        $created = false;
        if ($project === null) {
            try {
                $project = $this->projects->create($name, '');
                $created = true;
            } catch (DomainException $e) {
                return $e->getMessage();
            }
        }
        $this->projects->activate($channel, $chatId, $project->id, $threadId);
        return sprintf('%s«%s» — проект этого чата. Карточка и задачи будут в контексте со следующей беседы (/new).', $created ? 'Заведён проект. ' : '', $project->name);
    }
}
