<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\ForumChannel;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;

use function Mercurius\I18n\t;

/**
 * The forum topic the conversation goes on in: rename, close, reopen, delete. Projects open and close their topics
 * themselves (ForumTopics); this is for the rest. Deleting erases every message of the topic, so it waits for the owner.
 *
 * spec: brief/channels.md#форум-темы--проекты — topic changes are writes, deletion is irreversible
 */
final class ForumTopicTool implements Tool, GrantKeyed {
    public const NAME = 'forum_topic';
    private const ACTIONS = ['rename', 'close', 'reopen', 'delete'];

    public function __construct(
        private readonly Channel $channel,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.forum_topic.tema_foruma_v_kotoroy_idet')
            . t('tool.forum_topic.temu_proekta_pri_zavershenii_i')
            . t('tool.forum_topic.delete_stiraet_vsyu_perepisku_temy'), [
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

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    public function grantKey(array $args): ?string {
        return Args::string($args, 'action') === 'delete' ? 'forum:delete' : null;
    }

    public function describe(array $args): string {
        return 'forum_topic ' . (Args::string($args, 'action') ?? '') . ' ' . (Args::string($args, 'name') ?? '');
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
            return ToolResult::error(t('tool.forum_topic.eta_beseda_idet_ne_v'));
        }
        if (!$this->channel instanceof ForumChannel) {
            return ToolResult::error(t('tool.forum_topic.no_topics'));
        }
        $action = Args::string($args, 'action') ?? '';
        $name = trim(Args::string($args, 'name') ?? '');
        if ($action === 'rename' && $name === '') {
            return ToolResult::error(t('tool.forum_topic.nuzhno_novoe_imya_temy_name'));
        }
        $done = match ($action) {
            'rename' => $this->channel->editTopic($meta->chatId, $meta->threadId, $name),
            'close' => $this->channel->closeTopic($meta->chatId, $meta->threadId),
            'reopen' => $this->channel->reopenTopic($meta->chatId, $meta->threadId),
            'delete' => $this->channel->deleteTopic($meta->chatId, $meta->threadId),
            default => null,
        };
        return match ($done) {
            null => ToolResult::error(t('tool.forum_topic.neizvestnoe_deystvie') . $action),
            false => ToolResult::error(t('tool.forum_topic.telegram_ne_dal_izmenit_temu')),
            true => ToolResult::ok(match ($action) {
                'rename' => sprintf(t('tool.forum_topic.tema_pereimenovana'), $name),
                'close' => t('tool.forum_topic.tema_zakryta'),
                'reopen' => t('tool.forum_topic.tema_snova_otkryta'),
                default => t('tool.forum_topic.tema_udalena_vmeste_s_perepiskoy'),
            }),
        };
    }
}
