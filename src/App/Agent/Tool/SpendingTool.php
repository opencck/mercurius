<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\RunRepository;

/**
 * The agent's own spend: tokens of this run so far, a run by its number, recent runs, spend by day and model. The
 * owner asked how many tokens an answer took and the agent could only guess.
 *
 * spec: BRIEF.md#трассировка-и-метрики — the agent sees its own spend; a participant sees only the current run
 */
final class SpendingTool implements Tool {
    public const NAME = 'spending';

    public function __construct(
        private readonly TraceReports $reports,
        private readonly RunRepository $runs,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Расход токенов и денег. action: run — этот запуск (или run_id): токены, вызовы модели, '
            . 'время, стоимость; recent — последние запуски; days — расход по дням и моделям (days, по умолчанию 7). '
            . 'Числа бери отсюда, не прикидывай.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['run', 'recent', 'days']],
                'run_id' => ['type' => 'integer', 'minimum' => 1],
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? 'run';
        $owner = $context->principal->isOwner();
        if ($action !== 'run' && !$owner) {
            return ToolResult::error('Общий расход видит только владелец.');
        }
        if ($action === 'recent') {
            return ToolResult::ok($this->reports->runs(10));
        }
        if ($action === 'days') {
            return ToolResult::ok($this->reports->usage(Args::int($args, 'days') ?? 7));
        }
        $runId = Args::int($args, 'run_id') ?? $context->runId;
        $run = $this->runs->find($runId);
        if ($run === null || (!$owner && $run->principalId !== $context->principal->id)) {
            return ToolResult::error(sprintf('Запуска #%d нет.', $runId));
        }
        $now = $runId === $context->runId
            ? sprintf("Запуск #%d идёт сейчас: потрачено %d токенов за %d вызовов модели (этот ответ ещё не посчитан).\n\n", $run->id, $run->tokensUsed, $run->turns)
            : '';
        if (!$owner) {
            return ToolResult::ok(trim($now) === '' ? sprintf('Запуск #%d: %d токенов, %d вызовов модели.', $run->id, $run->tokensUsed, $run->turns) : trim($now));
        }
        return ToolResult::ok($now . ($this->reports->trace($runId) ?? 'Трассы этого запуска нет.'));
    }
}
