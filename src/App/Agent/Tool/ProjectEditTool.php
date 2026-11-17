<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\TaskProfile;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

/**
 * Keeps projects: the agent writes the card and the tasks itself; a new project, its goal or status, and a
 * participant's relation to it wait for the owner — those calls are irreversible, so the owner gets a card.
 *
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — the agent keeps the card, the goal and the status
 *       change with the owner's confirmation; a relation goes through the owner too and only from the participant's
 *       own words
 */
final class ProjectEditTool implements Tool {
    public const NAME = 'project_edit';
    public const ACTIONS = ['create', 'goal', 'status', 'card', 'add_task', 'task_status', 'relation', 'activate'];
    /** what waits for the owner */
    public const CONFIRMED = ['create', 'goal', 'status', 'relation'];
    public const RELATION_MAX = 1000;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Ведёт проекты. project — имя проекта (без него — проект этого чата). action: create — новый '
            . 'проект (text — цель); goal — новая цель (text); status — active, paused или done; card — полный новый текст карточки: состав, '
            . 'репозитории, серверы, стек, принятые решения; add_task — задача (text; parent_id — если это часть другой; profile — оценки '
            . '1–10 по осям, если ясны); task_status — task_id и status (open, doing, done, dropped), для done и dropped text — итог; '
            . 'relation — отношение собеседника к проекту его словами (роль, приоритет, ожидания), не твои выводы; activate — сделать '
            . 'проект проектом этого чата. create, goal, status и relation ждут подтверждения владельца.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'text' => ['type' => 'string', 'maxLength' => Project::CARD_MAX],
                'status' => ['type' => 'string', 'enum' => array_values(array_unique([...Project::STATUSES, ...ProjectTask::STATUSES]))],
                'task_id' => ['type' => 'integer', 'minimum' => 1],
                'parent_id' => ['type' => 'integer', 'minimum' => 1],
                'profile' => ['type' => 'object'],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action'), self::CONFIRMED, true) ? DangerClass::Irreversible : DangerClass::Write;
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
            return ToolResult::error('В проекте не хранят секреты: уберите их из текста.');
        }
        if ($action === 'create') {
            $name = trim(Args::string($args, 'project') ?? '');
            if ($name === '') {
                return ToolResult::error('Нужно имя проекта: project.');
            }
            try {
                $project = $this->projects->create($name, mb_substr($text, 0, Project::GOAL_MAX));
            } catch (DomainException $e) {
                return ToolResult::error($e->getMessage());
            }
            return ToolResult::ok(sprintf('Проект «%s» заведён.', $project->name));
        }
        $project = $this->project($args, $context);
        if ($project === null) {
            return ToolResult::error('Проект не найден: назови его в project или привяжи к чату (activate).');
        }
        return match ($action) {
            'goal' => $this->update($project, $text === '' ? null : mb_substr($text, 0, Project::GOAL_MAX), null, null, 'Цель'),
            'status' => in_array(Args::string($args, 'status'), Project::STATUSES, true)
                ? $this->update($project, null, Args::string($args, 'status'), null, 'Статус')
                : ToolResult::error('status проекта — active, paused или done.'),
            'card' => $this->update($project, null, null, $text, 'Карточка'),
            'add_task' => $this->addTask($project, $text, Args::int($args, 'parent_id'), $args),
            'task_status' => $this->taskStatus($project, Args::int($args, 'task_id'), Args::string($args, 'status'), $text),
            'relation' => $this->relation($project, $context, $text),
            'activate' => $this->activate($project, $context),
            default => ToolResult::error('Неизвестное действие: ' . $action),
        };
    }

    /** @param array<string, mixed> $args */
    private function project(array $args, ToolContext $context): ?Project {
        $name = Args::string($args, 'project');
        if ($name !== null) {
            return $this->projects->byName($name);
        }
        $meta = $this->conversations->meta($context->conversationId);
        return $meta === null ? null : $this->projects->active($meta->channel, $meta->chatId);
    }

    private function update(Project $project, ?string $goal, ?string $status, ?string $card, string $what): ToolResult {
        $this->projects->update($project->id, $goal, $status, $card);
        return ToolResult::ok(sprintf('%s проекта «%s» обновлена.', $what, $project->name));
    }

    /** @param array<string, mixed> $args */
    private function addTask(Project $project, string $title, ?int $parentId, array $args): ToolResult {
        if ($title === '' || mb_strlen($title) > ProjectTask::TITLE_MAX) {
            return ToolResult::error(sprintf('Задаче нужна формулировка до %d символов: text.', ProjectTask::TITLE_MAX));
        }
        if ($parentId !== null && $this->projects->task($parentId)?->projectId !== $project->id) {
            return ToolResult::error(sprintf('Задачи #%d в проекте «%s» нет.', $parentId, $project->name));
        }
        $profile = isset($args['profile']) && is_array($args['profile']) ? TaskProfile::fromArray($args['profile'])->nonTrivial() : [];
        $task = $this->projects->addTask($project->id, $title, $parentId, $profile);
        return ToolResult::ok(sprintf('Задача #%d добавлена в «%s».', $task->id, $project->name));
    }

    private function taskStatus(Project $project, ?int $taskId, ?string $status, string $result): ToolResult {
        $task = $taskId === null ? null : $this->projects->task($taskId);
        if ($task === null || $task->projectId !== $project->id) {
            return ToolResult::error(sprintf('Задачи #%d в проекте «%s» нет.', (int) $taskId, $project->name));
        }
        if (!in_array($status, ProjectTask::STATUSES, true)) {
            return ToolResult::error('status задачи — open, doing, done или dropped.');
        }
        $closing = $status === ProjectTask::DONE || $status === ProjectTask::DROPPED;
        if ($closing && $result === '') {
            return ToolResult::error('Закрытой задаче нужен итог: text.');
        }
        $this->projects->setTaskStatus($task->id, $status, $result === '' ? null : mb_substr($result, 0, ProjectTask::RESULT_MAX));
        return ToolResult::ok(sprintf('Задача #%d: %s.', $task->id, $status));
    }

    private function relation(Project $project, ToolContext $context, string $text): ToolResult {
        if ($text === '' || mb_strlen($text) > self::RELATION_MAX) {
            return ToolResult::error(sprintf('Отношение — словами собеседника, до %d символов: text.', self::RELATION_MAX));
        }
        $this->projects->setRelation($project->id, $context->principal->id, $text);
        return ToolResult::ok(sprintf('Отношение к проекту «%s» записано.', $project->name));
    }

    private function activate(Project $project, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error('Беседа не найдена.');
        }
        $this->projects->activate($meta->channel, $meta->chatId, $project->id);
        return ToolResult::ok(sprintf('«%s» — проект этого чата; карточка придёт в контекст со следующей беседы (/new).', $project->name));
    }
}
