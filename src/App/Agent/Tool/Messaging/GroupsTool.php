<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\ForumChannel;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\GroupGate;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Repository\GroupRepository;

use function Mercurius\I18n\t;

/**
 * The owner's groups: each with the decision, the mode and what the bot may do there — whether it sees every message
 * (an administrator, or the privacy mode off) and may manage topics; and a group asked again: the decision is forgotten
 * and a new card comes, so the owner changes the mode or sends the bot away.
 *
 * spec: brief/channels.md#группа
 */
final class GroupsTool implements Tool, GrantKeyed {
    public const NAME = 'groups';

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupGate $gate,
        /** topics are deleted through it; null — not here */
        private readonly ?ForumChannel $forum = null,
        /** a deleted topic is no longer a project's; null — not unbound */
        private readonly ?\OpenCCK\Domain\Repository\ProjectRepository $projects = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.groups.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'ask', 'delete_topic']],
                'group' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => t('tool.groups.group')],
                'topic' => ['type' => 'string', 'pattern' => '^[0-9]{1,12}$', 'description' => t('tool.groups.topic')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return match (Args::string($args, 'action')) {
            'ask' => DangerClass::Write,
            'delete_topic' => DangerClass::Irreversible,
            default => DangerClass::Read,
        };
    }

    /** spec: brief/agent-loop.md#разрешения-навсегда — deleting topics may be allowed for good, as any irreversible action */
    public function grantKey(array $args): ?string {
        return Args::string($args, 'action') === 'delete_topic' ? 'forum:delete' : null;
    }

    public function describe(array $args): string {
        $found = $this->find(trim(Args::string($args, 'group') ?? ''));
        return sprintf(t('tool.groups.delete_card'), Args::string($args, 'topic') ?? '?', is_string($found) ? (Args::string($args, 'group') ?? '') : $found->title);
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.groups.owner_only'));
        }
        if ((Args::string($args, 'action') ?? 'list') === 'list') {
            $lines = array_map(fn(Group $g): string => $this->line($g), $this->groups->all());
            return ToolResult::ok($lines === [] ? t('tool.groups.none') : implode("\n", $lines));
        }
        $found = $this->find(trim(Args::string($args, 'group') ?? ''));
        if (is_string($found)) {
            return ToolResult::error($found);
        }
        if (Args::string($args, 'action') === 'delete_topic') {
            return $this->deleteTopic($found, Args::string($args, 'topic') ?? '');
        }
        $group = $this->gate->ask($found->channel, $found->chatId);
        return $group === null ? ToolResult::error(t('service.group_gate.group_unknown')) : ToolResult::ok(sprintf(t('tool.groups.asked'), $group->title));
    }

    /** spec: brief/channels.md#форум-темы--проекты — a topic goes with all its messages; a project it was is unbound */
    private function deleteTopic(Group $group, string $topic): ToolResult {
        if (preg_match('~^[0-9]{1,12}$~', $topic) !== 1) {
            return ToolResult::error(t('tool.groups.topic_needed'));
        }
        if ($this->forum === null || !$this->forum->deleteTopic($group->chatId, $topic)) {
            return ToolResult::error(sprintf(t('tool.groups.topic_not_deleted'), $topic, $group->title));
        }
        $this->projects?->activate($group->channel, $group->chatId, null, $topic);
        return ToolResult::ok(sprintf(t('tool.groups.topic_deleted'), $topic, $group->title));
    }

    private function line(Group $g): string {
        $state = match (true) {
            $g->status === Group::PENDING => t('tool.groups.pending'),
            $g->status === Group::REFUSED => t('tool.groups.refused'),
            $g->mode === Group::FREE => t('tool.groups.free'),
            default => t('tool.groups.mention'),
        };
        $line = sprintf('«%s» (%s) — %s%s', $g->title, $g->chatId, $state, $g->forum ? t('tool.groups.forum') : '');
        if ($g->status === Group::REFUSED) {
            return $line;
        }
        $rights = $this->gate->rights($g->chatId);
        $notes = $rights === null ? [t('tool.groups.rights_unknown')] : array_values(array_filter([
            $rights->seesAll() ? t('tool.groups.sees_all') : t('tool.groups.sees_addressed'),
            $rights->canManageTopics ? t('tool.groups.topics_yes') : t('tool.groups.topics_no'),
        ]));
        return $line . '; ' . implode('; ', $notes);
    }

    /** A group by its number or a part of its title; why not, when none or several fit. */
    private function find(string $name): Group|string {
        if ($name === '') {
            return t('tool.groups.name_it');
        }
        $all = $this->groups->all();
        foreach ($all as $g) {
            if ($g->chatId === $name) {
                return $g;
            }
        }
        $fit = array_values(array_filter($all, static fn(Group $g): bool => mb_stripos($g->title, $name) !== false));
        return match (count($fit)) {
            1 => $fit[0],
            0 => sprintf(t('tool.groups.not_found'), $name),
            default => sprintf(t('tool.groups.several'), $name, implode(', ', array_map(static fn(Group $g): string => '«' . $g->title . '» (' . $g->chatId . ')', $fit))),
        };
    }
}
