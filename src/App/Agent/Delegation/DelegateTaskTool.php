<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\TaskProfile;
use OpenCCK\App\Agent\Tool\LongRunning;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * Hands parts of a task to workers and returns their short reports. A write: it spends the run's budget.
 *
 * spec: BRIEF.md#часть-1--синхронное-делегирование
 * ref: references/hermes-agent/tools/delegate_tool.py@e96221fa:L843-L922 — tasks with goal and context ("each child
 *      sees only its own context"); here also the expected output, the tools and the model role
 * ref: references/hermes-agent/tools/delegate_tool_results.py@e96221fa:L254-L309 (_apply_summary_budget) — a cap on
 *      each summary so the reports do not crowd out the orchestrator's context
 */
final class DelegateTaskTool implements Tool, LongRunning {
    public const NAME = 'delegate_task';
    private const MAX_TASKS = 5;

    public function __construct(
        private readonly WorkerRunner $workers,
        private readonly ?BackgroundDelegations $background = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Поручает части задачи воркерам — отдельным агентам с чистым контекстом. '
            . 'Дели, только когда части независимы и каждая требует заметной работы: у каждого воркера свои накладные '
            . 'расходы. Воркер не видит беседы — в goal и context пиши всё нужное. Задачи только на чтение идут '
            . 'параллельно. role: cheap — простая массовая работа, main — обычная, strong — сложная. Возвращает отчёт по '
            . 'каждой задаче; сведи их сам и разреши противоречия. background: true — для долгих частей, ответ на которые '
            . 'не нужен в этом ходе: инструмент сразу вернёт номер, а отчёт придёт в беседу отдельным сообщением.', [
            'type' => 'object',
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_TASKS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'goal' => ['type' => 'string', 'minLength' => 1, 'description' => 'Что сделать, конкретно и самодостаточно'],
                            'context' => ['type' => 'string', 'description' => 'Фон, который нужен воркеру'],
                            'output' => ['type' => 'string', 'description' => 'Какой нужен ответ'],
                            'tools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Инструменты воркера'],
                            'role' => ['type' => 'string', 'enum' => ['cheap', 'main', 'strong']],
                            'profile' => [
                                'type' => 'object',
                                'description' => 'Вместо role: оценки 1–10, что нужно от модели; код подберёт самую дешёвую подходящую. Цена ошибки поднимает планку',
                                'properties' => array_fill_keys(TaskProfile::AXES, ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]),
                                'additionalProperties' => false,
                            ],
                            'verify' => ['type' => 'boolean', 'description' => 'Проверить ответ отдельным проверяющим с чистым контекстом: для частей, где ошибка дорога'],
                        ],
                        'required' => ['goal'],
                        'additionalProperties' => false,
                    ],
                ],
                'background' => ['type' => 'boolean', 'description' => 'Выполнить в фоне: ответ придёт отдельным сообщением'],
            ],
            'required' => ['tasks'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    /** Workers read pages and files: their reports carry what they read, so they are data, not instructions. */
    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 900;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $tasks = [];
        $raw = isset($args['tasks']) && is_array($args['tasks']) ? $args['tasks'] : [];
        if ($raw !== []) {
            foreach (array_keys($raw) as $key) {
                /** @var mixed $item */
                $item = $raw[$key];
                if (!is_array($item)) {
                    continue;
                }
                $tasks[] = new WorkerTask(
                    self::field($item, 'goal') ?? '',
                    self::field($item, 'context') ?? '',
                    self::field($item, 'output') ?? '',
                    self::names($item),
                    self::field($item, 'role') ?? 'main',
                    isset($item['profile']) && is_array($item['profile']) ? TaskProfile::fromArray($item['profile']) : null,
                    ($item['verify'] ?? false) === true,
                );
            }
        }
        if ($tasks === [] || count($tasks) > self::MAX_TASKS) {
            return ToolResult::error(sprintf('Нужно от 1 до %d задач.', self::MAX_TASKS));
        }
        try {
            if (($args['background'] ?? false) === true && $this->background !== null) {
                $delegation = $this->background->spawn($context, $tasks);
                return ToolResult::ok(sprintf(
                    'Фоновое делегирование #%d начато: задач %d. Отчёт придёт в эту беседу отдельным сообщением, когда все '
                    . 'задачи закончатся. Сейчас его не жди: скажи владельцу, что работа идёт в фоне.',
                    $delegation->id,
                    count($tasks),
                ));
            }
            $reports = $this->workers->run($context, $tasks);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(WorkerReports::text($reports));
    }

    /** @param array<array-key, mixed> $item */
    private static function field(array $item, string $key): ?string {
        return isset($item[$key]) && is_string($item[$key]) ? $item[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $item
     * @return list<string>
     */
    private static function names(array $item): array {
        $tools = isset($item['tools']) && is_array($item['tools']) ? $item['tools'] : [];
        $names = [];
        foreach (array_keys($tools) as $i) {
            if (isset($tools[$i]) && is_string($tools[$i])) {
                $names[] = $tools[$i];
            }
        }
        return $names;
    }
}
