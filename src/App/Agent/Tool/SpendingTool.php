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
        /** finds the run that wrote a message of the chat */
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $messages = null,
        private readonly ?\OpenCCK\Domain\Repository\ConversationRepository $conversations = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Расход токенов и денег. action: run — этот запуск, запуск run_id или запуск, '
            . 'который написал сообщение message_id в этом чате (номер запуска есть и в заголовке ответа на твоё сообщение); '
            . 'recent — последние запуски; days — расход по дням и моделям (days, по умолчанию 7). '
            . 'Числа бери только отсюда и только из вызова в этом ответе: не прикидывай и не бери из прошлых ответов.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['run', 'recent', 'days']],
                'run_id' => ['type' => 'integer', 'minimum' => 1],
                'message_id' => ['type' => 'integer', 'minimum' => 1],
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
        $messageId = Args::int($args, 'message_id');
        $prefix = '';
        if ($messageId !== null) {
            $meta = $this->conversations?->meta($context->conversationId);
            $found = $meta === null ? null : $this->messages?->runOf($meta->channel, $meta->chatId, (string) $messageId);
            if ($found === null) {
                return ToolResult::error(sprintf('Неизвестно, какой запуск написал сообщение #%d: такие записи ведутся с 2026-11-17. Не угадывай — скажи, что не знаешь.', $messageId));
            }
            $runId = $found;
            $prefix = sprintf("Сообщение #%d написал запуск #%d.\n", $messageId, $found);
        }
        $run = $this->runs->find($runId);
        if ($run === null || (!$owner && $run->principalId !== $context->principal->id)) {
            return ToolResult::error(sprintf('Запуска #%d нет.', $runId));
        }
        $summary = $prefix . ($runId === $context->runId
            ? sprintf('Запуск #%d идёт сейчас: потрачено %d токенов за %d вызовов модели (этот ответ ещё не посчитан).', $run->id, $run->tokensUsed, $run->turns)
            : sprintf('Запуск #%d: итого %d токенов, %d вызовов модели.', $run->id, $run->tokensUsed, $run->turns));
        if (!$owner) {
            return ToolResult::ok($summary);
        }
        return ToolResult::ok($summary . "\n\n" . ($this->reports->trace($runId) ?? 'Трассы этого запуска нет.'));
    }
}
