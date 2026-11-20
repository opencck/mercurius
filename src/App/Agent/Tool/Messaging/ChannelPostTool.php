<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use DateTimeImmutable;
use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Group\ChannelPost;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Domain\Repository\ChannelRepository;

use function Mercurius\I18n\t;

/**
 * Posts in channels where the bot is an admin. Subscribers see a post at once and it cannot be taken back, so
 * publishing, editing and deleting are irreversible: each waits for the owner's card with the whole post, and the owner
 * may allow an action in a channel for good ("publish in X" without asking, deletion still asked).
 *
 * spec: brief/channels.md#канал-паблик
 */
final class ChannelPostTool implements Tool, GrantKeyed {
    public const NAME = 'channel_post';
    private const ACTIONS = ['list', 'history', 'publish', 'edit', 'delete'];
    private const CHANGES = ['publish', 'edit', 'delete'];
    private const HISTORY = 10;
    private const TEXT_MAX = 4096;

    public function __construct(
        private readonly Channel $channel,
        private readonly ChannelRepository $channels,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.channel_post.posty_v_kanalah_gde_bot')
            . t('tool.channel_post.kanala_publish_text_edit_message')
            . t('tool.channel_post.on_uvidit_post_tselikom_channel'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'channel' => ['type' => 'string', 'maxLength' => 200],
                'text' => ['type' => 'string', 'maxLength' => self::TEXT_MAX],
                'message_id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action'), self::CHANGES, true) ? DangerClass::Irreversible : DangerClass::Read;
    }

    public function grantKey(array $args): ?string {
        $action = Args::string($args, 'action');
        $target = $this->target($args);
        return in_array($action, self::CHANGES, true) && $target !== null ? 'channel:' . $action . ':' . $target->chatId : null;
    }

    public function describe(array $args): string {
        $target = $this->target($args);
        $where = $target === null ? t('tool.channel_post.kanal_ne_nayden') : sprintf('«%s»', $target->title);
        $text = Args::string($args, 'text') ?? '';
        return match (Args::string($args, 'action')) {
            'publish' => sprintf(t('tool.channel_post.publikatsiya_v_kanal'), $where, $text),
            'edit' => sprintf(t('tool.channel_post.pravka_posta_v_kanale_novyy'), Args::int($args, 'message_id') ?? 0, $where, $text),
            'delete' => sprintf(t('tool.channel_post.udalenie_posta_v_kanale'), Args::int($args, 'message_id') ?? 0, $where),
            default => t('tool.channel_post.kanaly'),
        };
    }

    /** Posts are written by people outside: they come to the model as data. */
    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        if ($action === 'list') {
            return ToolResult::ok($this->list());
        }
        $target = $this->target($args);
        if ($target === null) {
            return ToolResult::error(t('tool.channel_post.kanal_ne_nayden_ili_ih'));
        }
        if ($action === 'history') {
            return ToolResult::ok($this->history($target));
        }
        $text = trim(Args::string($args, 'text') ?? '');
        $messageId = Args::int($args, 'message_id');
        $refusal = $this->refusal($target, $action, $text, $messageId);
        if ($refusal !== null) {
            return ToolResult::error($refusal);
        }
        $id = (string) $messageId;
        if ($action === 'publish') {
            $sent = $this->channel->send(new OutgoingMessage($target->chatId, $text, parseMode: ParseMode::Markdown));
            if (!$sent->success || $sent->messageId === null) {
                return ToolResult::error(t('tool.channel_post.telegram_ne_prinyal_post'));
            }
            $this->channels->savePost($target->channel, $target->chatId, $sent->messageId, $text, new DateTimeImmutable(), false);
            return ToolResult::ok(sprintf(t('tool.channel_post.opublikovano_v_post'), $target->title, $sent->messageId));
        }
        if ($action === 'edit') {
            if (!$this->channel->edit($target->chatId, $id, $text, ParseMode::Markdown)->success) {
                return ToolResult::error(t('tool.channel_post.telegram_ne_dal_izmenit_post'));
            }
            $this->channels->savePost($target->channel, $target->chatId, $id, $text, new DateTimeImmutable(), true);
            return ToolResult::ok(sprintf(t('tool.channel_post.post_v_izmenen'), $id, $target->title));
        }
        if (!$this->channel->delete($target->chatId, $id)) {
            return ToolResult::error(t('tool.channel_post.telegram_ne_dal_udalit_post'));
        }
        $this->channels->deletePost($target->channel, $target->chatId, $id);
        return ToolResult::ok(sprintf(t('tool.channel_post.post_v_udalen'), $id, $target->title));
    }

    private function list(): string {
        $all = $this->channels->all();
        if ($all === []) {
            return t('tool.channel_post.kanalov_net_bota_nuzhno_sdelat');
        }
        return implode("\n", array_map(
            static fn(PublicChannel $c): string => sprintf('«%s» (%s): %s', $c->title, $c->chatId, $c->available ? $c->rights() : t('tool.channel_post.nedostupen')),
            $all,
        ));
    }

    private function history(PublicChannel $target): string {
        $posts = $this->channels->posts($target->channel, $target->chatId, self::HISTORY);
        if ($posts === []) {
            return sprintf(t('tool.channel_post.v_kanale_postov_poka_ne'), $target->title);
        }
        return implode("\n\n", array_map(
            static fn(ChannelPost $p): string => sprintf('#%s %s%s: %s', $p->messageId, $p->postedAt->format('Y-m-d H:i'), $p->editedAt === null ? '' : t('tool.channel_post.izmenen'), $p->text),
            $posts,
        ));
    }

    /** Why the change cannot go, checked before the owner's card; null — it can. */
    private function refusal(PublicChannel $target, string $action, string $text, ?int $messageId): ?string {
        if (!in_array($action, self::CHANGES, true)) {
            return t('tool.channel_post.neizvestnoe_deystvie') . $action;
        }
        if (!$target->available) {
            return sprintf(t('tool.channel_post.v_kanale_bot_bolshe_ne'), $target->title);
        }
        $allowed = match ($action) {
            'publish' => $target->canPost,
            'edit' => $target->canEdit,
            default => $target->canDelete,
        };
        if (!$allowed) {
            return sprintf(t('tool.channel_post.v_kanale_u_bota_net'), $target->title, $target->rights());
        }
        if ($action !== 'delete' && $text === '') {
            return t('tool.channel_post.nuzhen_tekst_posta_text');
        }
        if ($action !== 'publish' && $messageId === null) {
            return t('tool.channel_post.nuzhen_nomer_posta_message_id');
        }
        if ($text !== '' && SecretMasker::mask($text) !== $text) {
            return t('tool.channel_post.v_post_ne_kladut_sekrety');
        }
        return null;
    }

    /**
     * The channel named by title or id; without a name — the only available one.
     *
     * @param array<string, mixed> $args
     */
    private function target(array $args): ?PublicChannel {
        $name = mb_strtolower(trim(Args::string($args, 'channel') ?? ''));
        $all = $this->channels->all();
        if ($name === '') {
            $available = array_values(array_filter($all, static fn(PublicChannel $c): bool => $c->available));
            return count($available) === 1 ? $available[0] : null;
        }
        foreach ($all as $channel) {
            if ($channel->chatId === $name || mb_strtolower($channel->title) === $name) {
                return $channel;
            }
        }
        return null;
    }
}
