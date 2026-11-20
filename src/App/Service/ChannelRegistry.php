<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\IncomingPost;
use Mercurius\Messenger\MembershipHandler;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\PostHandler;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Domain\Repository\ChannelRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * Channels where the bot is an admin. The rights come from Telegram with the membership change; a channel is available
 * while the bot may post there. The owner is told when a channel becomes available or stops being so. Posts of the
 * channel are kept, so the agent sees what is already published.
 *
 * spec: brief/channels.md#канал-паблик
 * ref: references/hermes-agent/plugins/platforms/telegram/telegram_intake.py@e96221fa:L6-L18 (effective_update_message) — hermes takes channel posts
 *      in like messages; here they are the channel's history, not questions to the agent
 */
final class ChannelRegistry implements MembershipHandler, PostHandler {
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
        private readonly LoggerInterface $logger,
    ) {}

    public function handleMembership(IncomingMembership $change): void {
        if ($change->chatType !== 'channel') {
            return;
        }
        $creator = $change->status === 'creator';
        $admin = $creator || $change->status === 'administrator';
        $right = static fn(string $name): bool => $creator || ($admin && ($change->rights[$name] ?? false));
        $now = new PublicChannel(
            $change->channel,
            $change->chatId,
            mb_substr($change->chatTitle ?? $change->chatId, 0, 200),
            $right('can_post_messages'),
            $right('can_edit_messages'),
            $right('can_delete_messages'),
            $right('can_post_messages'),
        );
        $before = $this->channels->save($now);
        $this->logger->notice('Channel rights recorded', ['chat' => $now->chatId, 'available' => $now->available, 'rights' => $now->rights()]);
        $changed = $before !== null && $before->rights() !== $now->rights();
        $text = match (true) {
            $now->available && $before?->available !== true => sprintf(
                t('service.channel_registry.ya_administrator_kanala_publikuyu_pravly'),
                $now->title,
                $now->rights(),
            ),
            !$now->available && $before?->available === true => sprintf(t('service.channel_registry.ya_bolshe_ne_mogu_publikovat'), $now->title),
            $now->available && $changed => sprintf(t('service.channel_registry.prava_v_kanale_izmenilis'), $now->title, $now->rights()),
            default => null,
        };
        if ($text !== null) {
            $this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), $text));
        }
    }

    public function handlePost(IncomingPost $post): void {
        $this->channels->savePost($post->channel, $post->chatId, $post->messageId, $post->text, $post->postedAt, $post->isEdited);
    }
}
