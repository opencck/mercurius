<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * The owner's commands for projects: the list, the project of this chat, binding a chat to a project.
 *
 * spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту — the active project is set by the conversation or by
 *       the owner's command; a change takes effect from the next conversation
 */
final class ProjectCommands {
    private const LIST = 30;
    private const TASKS = 20;

    public function __construct(private readonly ProjectRepository $projects) {}

    public function list(string $channel, string $chatId, string $threadId = ''): string {
        $all = $this->projects->all(self::LIST);
        if ($all === []) {
            return t('service.project_commands.proektov_poka_net_project_imya');
        }
        $active = $this->projects->active($channel, $chatId, $threadId);
        return t('service.project_commands.proekty') . implode("\n", array_map(
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
                return t('service.project_commands.u_etogo_chata_net_proekta');
            }
            $tasks = $this->projects->openTasks($project->id, self::TASKS);
            return SecretMasker::mask(implode("\n\n", array_filter([
                sprintf(t('service.project_commands.proekt_chata'), $project->name, ProjectContext::status($project->status)),
                $project->goal === '' ? '' : t('service.project_commands.tsel') . $project->goal,
                $project->card === '' ? '' : t('service.project_commands.kartochka') . $project->card,
                $tasks === [] ? t('service.project_commands.otkrytyh_zadach_net') : t('service.project_commands.otkrytye_zadachi') . implode("\n", array_map(ProjectContext::task(...), $tasks)),
            ], static fn(string $part): bool => $part !== '')));
        }
        if ($name === '-') {
            $this->projects->activate($channel, $chatId, null, $threadId);
            return t('service.project_commands.chat_otvyazan_ot_proekta_so');
        }
        if (mb_strlen($name) > Project::NAME_MAX) {
            return sprintf(t('service.project_commands.imya_proekta_do_simvolov'), Project::NAME_MAX);
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
        return sprintf(t('service.project_commands.proekt_etogo_chata_kartochka_i'), $created ? t('service.project_commands.zaveden_proekt') : '', $project->name);
    }
}
