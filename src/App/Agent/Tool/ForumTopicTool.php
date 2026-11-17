<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;

/**
 * The forum topic the conversation goes on in: rename, close, reopen, delete. Projects open and close their topics
 * themselves (ForumTopics); this is for the rest. Deleting erases every message of the topic, so it waits for the owner.
 *
 * spec: BRIEF.md#форум-темы--проекты — topic changes are writes, deletion is irreversible
 */
final class ForumTopicTool implements Tool {
    public const NAME = 'forum_topic';
    private const ACTIONS = ['rename', 'close', 'reopen', 'delete'];

    public function __construct(
        private readonly Channel $channel,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Тема форума, в которой идёт эта беседа: rename (name), close, reopen, delete. '
            . 'Тему проекта при завершении и возобновлении проекта закрывать и открывать не нужно — это делается само. '
            . 'delete стирает всю переписку темы и требует подтверждения владельца.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'name' => ['type' => 'string', 'maxLength' => 128],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'delete' ? DangerClass::Irreversible : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null || $meta->threadId === '') {
            return ToolResult::error('Эта беседа идёт не в теме форума.');
        }
        $action = Args::string($args, 'action') ?? '';
        $name = trim(Args::string($args, 'name') ?? '');
        if ($action === 'rename' && $name === '') {
            return ToolResult::error('Нужно новое имя темы: name.');
        }
        $done = match ($action) {
            'rename' => $this->channel->editTopic($meta->chatId, $meta->threadId, $name),
            'close' => $this->channel->closeTopic($meta->chatId, $meta->threadId),
            'reopen' => $this->channel->reopenTopic($meta->chatId, $meta->threadId),
            'delete' => $this->channel->deleteTopic($meta->chatId, $meta->threadId),
            default => null,
        };
        return match ($done) {
            null => ToolResult::error('Неизвестное действие: ' . $action),
            false => ToolResult::error('Telegram не дал изменить тему: нужно право боту управлять темами.'),
            true => ToolResult::ok(match ($action) {
                'rename' => sprintf('Тема переименована: «%s».', $name),
                'close' => 'Тема закрыта.',
                'reopen' => 'Тема снова открыта.',
                default => 'Тема удалена вместе с перепиской.',
            }),
        };
    }
}
