<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use DomainException;
use Mercurius\Llm\ToolDefinition;
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

    public function __construct(private readonly WorkerRunner $workers) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Поручает части задачи воркерам — отдельным агентам с чистым контекстом. '
            . 'Дели, только когда части независимы и каждая требует заметной работы: у каждого воркера свои накладные '
            . 'расходы. Воркер не видит беседы — в goal и context пиши всё нужное. Задачи только на чтение идут '
            . 'параллельно. role: cheap — простая массовая работа, main — обычная, strong — сложная. Возвращает отчёт по '
            . 'каждой задаче; сведи их сам и разреши противоречия.', [
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
                        ],
                        'required' => ['goal'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['tasks'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
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
                );
            }
        }
        if ($tasks === [] || count($tasks) > self::MAX_TASKS) {
            return ToolResult::error(sprintf('Нужно от 1 до %d задач.', self::MAX_TASKS));
        }
        try {
            $reports = $this->workers->run($context, $tasks);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(self::text($reports));
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

    /** @param list<WorkerReport> $reports */
    private static function text(array $reports): string {
        $cap = min(4000, max(500, intdiv(16000, max(1, count($reports)))));
        $lines = [sprintf('Отчёт воркеров (%d):', count($reports))];
        foreach ($reports as $report) {
            $summary = $report->summary === '' ? '(итога нет)' : $report->summary;
            if (mb_strlen($summary) > $cap) {
                $summary = mb_substr($summary, 0, $cap) . sprintf("\n… [итог длиннее, полностью — в запуске #%d]", (int) $report->runId);
            }
            $lines[] = '';
            $lines[] = sprintf(
                '### Задача %d — %s%s',
                $report->index,
                $report->status,
                $report->reason !== null && $report->status !== 'done' ? ' (' . $report->reason . ')' : '',
            );
            $lines[] = sprintf('Модель %s, ходов %d, токенов %d%s', $report->model, $report->turns, $report->tokens, $report->runId === null ? '' : ', запуск #' . $report->runId);
            $lines[] = $summary;
        }
        return implode("\n", $lines);
    }
}
