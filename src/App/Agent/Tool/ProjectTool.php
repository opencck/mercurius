<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

/**
 * Projects of the owner: the list, or one project with its card, open tasks and the caller's relation to it.
 *
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — the other projects on request
 */
final class ProjectTool implements Tool {
    public const NAME = 'project';
    private const LIST = 30;
    private const TASKS = 30;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Проекты владельца. Без name — список проектов (отмечен проект этого чата); с name — карточка '
            . 'проекта, его открытые задачи и отношение собеседника к проекту.', [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        $active = $meta === null ? null : $this->projects->active($meta->channel, $meta->chatId, $meta->threadId);
        if ($active !== null && !$active->visibleTo($context->principal)) {
            $active = null;
        }
        $name = Args::string($args, 'name');
        if ($name === null) {
            $all = $this->projects->all(self::LIST, Project::scopeOf($context->principal));
            if ($all === []) {
                return ToolResult::ok('Проектов пока нет.');
            }
            return ToolResult::ok(implode("\n", array_map(
                fn(Project $p): string => sprintf(
                    '%s — %s%s; открытых задач: %d%s',
                    $p->name,
                    ProjectContext::status($p->status),
                    $p->goal === '' ? '' : '; цель: ' . mb_substr($p->goal, 0, 120),
                    count($this->projects->openTasks($p->id, 100)),
                    $active?->id === $p->id ? ' (проект этого чата)' : '',
                ),
                $all,
            )));
        }
        $project = $this->projects->byName($name, Project::scopeOf($context->principal));
        if ($project === null) {
            return ToolResult::error(sprintf('Проекта «%s» нет.', $name));
        }
        $parts = [sprintf('%s — %s', $project->name, ProjectContext::status($project->status))];
        $parts[] = 'Цель: ' . ($project->goal === '' ? '(не задана)' : $project->goal);
        $parts[] = "Карточка:\n" . ($project->card === '' ? '(пусто)' : $project->card);
        $relation = $this->projects->relation($project->id, $context->principal->id);
        if ($relation !== null) {
            $parts[] = "Отношение собеседника:\n" . $relation;
        }
        $tasks = $this->projects->openTasks($project->id, self::TASKS);
        $parts[] = "Открытые задачи:\n" . ($tasks === [] ? '(нет)' : implode("\n", array_map(ProjectContext::task(...), $tasks)));
        return ToolResult::ok(SecretMasker::mask(implode("\n\n", $parts)));
    }
}
