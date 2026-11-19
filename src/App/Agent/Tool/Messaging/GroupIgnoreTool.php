<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\GroupIgnoreRepository;

use function Mercurius\I18n\t;

/**
 * A participant of a group names a bot or a visitor the agent should not hear there, or hears again; the list of the
 * group. Only in the conversation of a group: the private chat has no one to ignore.
 *
 * spec: BRIEF.md#группа — participants name bots and visitors the agent ignores
 * ref: references/hermes-agent/gateway/bot_loop_guard.py@e96221fa:L80-L90 (configured_bot_user) — hermes keeps a list of
 *      peer bots in its config; here participants keep the list of a group in the conversation, by id or @name
 */
final class GroupIgnoreTool implements Tool {
    public const NAME = 'group_ignore';

    public function __construct(
        private readonly GroupIgnoreRepository $ignores,
        private readonly ConversationRepository $conversations,
        private readonly \OpenCCK\Domain\Repository\GroupRepository $groups,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.group_ignore.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'add', 'remove']],
                'who' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'list' ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null || $this->groups->find($meta->channel, $meta->chatId) === null) {
            return ToolResult::error(t('tool.group_ignore.group_only'));
        }
        $action = Args::string($args, 'action') ?? 'list';
        if ($action === 'list') {
            $list = $this->ignores->list($meta->channel, $meta->chatId);
            return ToolResult::ok($list === [] ? t('tool.group_ignore.empty') : t('tool.group_ignore.list') . ' ' . implode(', ', $list));
        }
        $who = mb_strtolower(ltrim(trim(Args::string($args, 'who') ?? ''), '@'));
        if (preg_match('~^[a-z0-9_]{1,64}$~', $who) !== 1) {
            return ToolResult::error(t('tool.group_ignore.bad_who'));
        }
        if ($action === 'add') {
            $this->ignores->add($meta->channel, $meta->chatId, $who, $context->principal->id);
            return ToolResult::ok(sprintf(t('tool.group_ignore.added'), $who));
        }
        return $this->ignores->remove($meta->channel, $meta->chatId, $who)
            ? ToolResult::ok(sprintf(t('tool.group_ignore.removed'), $who))
            : ToolResult::error(sprintf(t('tool.group_ignore.not_listed'), $who));
    }
}
