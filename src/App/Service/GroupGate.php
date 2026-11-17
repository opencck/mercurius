<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MembershipHandler;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Repository\GroupRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * Work in group chats. A new group waits for the owner's card (by mention, freely, or leave); until then the bot is
 * silent, after a refusal it leaves. In an approved group the agent answers a mention, a reply to its message or
 * /command@bot — or, in the free mode, decides itself and may stay silent ([SILENT]). Other messages are kept as the
 * recent talk of the group and given to the next run as data. Strangers are not answered; other bots are not seen.
 *
 * spec: BRIEF.md#telegram-группы-каналы-и-темы — approved by the owner 2026-11-17
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221fa:L5760-L5800 (_should_process_message) —
 *      mention, reply to the bot and a command addressed to it; L5183-L5187, L5550-L5570 — unmentioned talk observed
 *      without running the agent; gateway/response_filters.py@e96221fa:L242-L256 — silence allowed in a free chat.
 *      Here a group must be approved by the owner first, and the observed talk is kept in memory for the next run.
 */
final class GroupGate implements CallbackHandler, MembershipHandler {
    public const RECENT = 20;
    public const SILENT = '[SILENT]';

    /** Lines of the talk older than this do not go into a run. */
    private const RECENT_SECONDS = 86400;
    private readonly \OpenCCK\Domain\Repository\GroupTalkRepository $talk;
    /** @var array<string, true> groups left in this process */
    private array $left = [];

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
        private readonly string $botId,
        private readonly ?string $botUsername,
        private readonly LoggerInterface $logger,
        /** forum topics as projects; null — topics are just threads */
        private readonly ?ForumTopics $forum = null,
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $runMessages = null,
        /** records strangers of approved groups as visitors and shows them to the owner */
        private readonly ?VisitorService $visitors = null,
        /** the observed talk by chat and topic; null — in the memory of the process */
        ?\OpenCCK\Domain\Repository\GroupTalkRepository $talk = null,
    ) {
        $this->talk = $talk ?? new \OpenCCK\Infrastructure\Repository\InMemoryGroupTalkRepository();
    }

    /**
     * What to do with a group message: null — nothing (silent); otherwise who speaks and the message for the agent.
     *
     * @return array{Principal, IncomingMessage, bool}|null the speaker, the message for the agent, whether it may stay silent
     */
    public function route(IncomingMessage $message): ?array {
        // spec: BRIEF.md#форум-темы--проекты — Telegram says with every message whether the group is a forum
        /** @var mixed $chat */
        $chat = $message->raw['chat'] ?? null;
        $forum = is_array($chat) && ($chat['is_forum'] ?? false) === true;
        $group = $this->groups->find($message->channel, $message->chatId);
        if ($group === null) {
            $title = self::title($message);
            if ($this->groups->create($message->channel, $message->chatId, $title)) {
                $this->groups->setForum($message->channel, $message->chatId, $forum);
                $this->card($message->chatId, $title);
            }
            return null;
        }
        if ($group->status === Group::REFUSED) {
            if (!isset($this->left[$group->chatId])) {
                $this->left[$group->chatId] = true;
                $this->channel->leave($group->chatId);
            }
            return null;
        }
        if ($forum !== $group->forum) {
            $this->groups->setForum($message->channel, $message->chatId, $forum);
        }
        /** @var mixed $opened */
        $opened = $message->raw['forum_topic_created'] ?? null;
        if (is_array($opened)) {
            $this->topicOpened($message, $opened);
            return null;
        }
        $speaker = ($message->displayName ?? $message->userName ?? $message->userId);
        // spec: BRIEF.md#группа — the talk of a topic is its own and survives a restart
        $thread = MessageMeta::topicKey($message);
        $context = $this->talk->recent($message->channel, $message->chatId, $thread, self::RECENT, self::RECENT_SECONDS);
        $this->remember($message->channel, $message->chatId, $thread, sprintf('#%s %s%s: %s', $message->messageId, $speaker, $message->threadId === null ? '' : t('service.group_gate.tema') . $message->threadId . ')', mb_substr(trim($message->text), 0, 500)));
        if ($group->status !== Group::APPROVED) {
            return null;
        }
        if ($this->visitors !== null && $this->principals->findByIdentity($message->channel, $message->userId) === null) {
            // spec: BRIEF.md#группа — the owner can give a group member rights before they write to the bot
            $this->visitors->admit($message, $group->title);
            return null;
        }
        $addressed = $this->addressed($message);
        if (!$addressed && $group->mode !== Group::FREE) {
            return null;
        }
        $principal = $this->principals->findByIdentity($message->channel, $message->userId);
        if ($principal === null || $principal->status !== PrincipalStatus::Active) {
            return null;
        }
        $text = $this->strip(trim($message->text));
        if ($text === '' && !$addressed) {
            return null;
        }
        $meta = array_filter([MessageMeta::topic($message), MessageMeta::reply($message, $this->botId, $this->runOfReply($message))]);
        // ref: references/hermes-agent/gateway/session.py@e96221fa:L296-L307 (neutralize_untrusted_inline_text) — a name or a
        //      title is one short line: a line break could pose as a new section of the prompt
        $prefix = sprintf(t('service.group_gate.gruppa_pishet_eto_soobschenie'), MessageMeta::inline($group->title), MessageMeta::inline($speaker), $message->messageId, $meta === [] ? '' : '; ' . implode('; ', $meta));
        $prefix .= $group->mode === Group::FREE && !$addressed
            ? t('service.group_gate.tebya_ne_zvali_otvet_tolko') . self::SILENT . '.]'
            : ']';
        if ($context !== []) {
            $prefix .= "\n" . UntrustedWrapper::wrap('group-chat', t('service.group_gate.poslednie_soobscheniya_gruppy') . implode("\n", array_slice($context, -self::RECENT)));
        }
        return [$principal, new IncomingMessage(
            $message->channel,
            $message->chatId,
            $message->userId,
            $message->userName,
            $message->displayName,
            $prefix . "\n\n" . $text,
            $message->messageId,
            $message->replyToMessageId,
            false,
            $message->receivedAt,
            $message->updateId,
            $message->threadId,
            $message->isEdited,
            $message->raw,
        ), $group->mode === Group::FREE && !$addressed];
    }

    /**
     * The bot was added to a group or removed from it. Added: the owner gets the card at once, not after the first
     * message; a refused group is left again. Removed: the group waits for the owner again if the bot comes back.
     * hermes has no handling of my_chat_member to follow; the card is the same as for the first message.
     */
    public function handleMembership(IncomingMembership $change): void {
        if (!in_array($change->chatType, ['group', 'supergroup'], true)) {
            return;
        }
        $group = $this->groups->find($change->channel, $change->chatId);
        $title = $change->chatTitle ?? $change->chatId;
        if (!$change->isMember()) {
            unset($this->left[$change->chatId]);
            $this->talk->forget($change->channel, $change->chatId);
            if ($group !== null && $group->status === Group::APPROVED) {
                $this->groups->decide($change->channel, $change->chatId, Group::PENDING, $group->mode);
                $this->channel->send(new OutgoingMessage($this->ownerChatId, sprintf(t('service.group_gate.menya_ubrali_iz_gruppy'), $group->title)));
            }
            $this->logger->notice('Bot removed from a group', ['chat' => $change->chatId, 'status' => $change->status]);
            return;
        }
        $joined = !in_array($change->oldStatus, ['creator', 'administrator', 'member', 'restricted'], true);
        if ($group === null) {
            if ($this->groups->create($change->channel, $change->chatId, $title)) {
                $this->card($change->chatId, $title);
            }
        } elseif ($group->status === Group::REFUSED) {
            $this->left[$group->chatId] = true;
            $this->channel->leave($group->chatId);
        } elseif ($group->status === Group::PENDING && $joined) {
            $this->card($change->chatId, $group->title);
        }
        $this->logger->notice('Bot added to a group', ['chat' => $change->chatId, 'status' => $change->status, 'by' => $change->userId]);
    }

    public function handleCallback(IncomingCallback $callback): void {
        if ($callback->chatId !== $this->ownerChatId || preg_match('/^gr:(-?\d+):([mfr])$/', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        [$status, $mode, $outcome] = match ($m[2]) {
            'm' => [Group::APPROVED, Group::MENTION, t('service.group_gate.rabotayu_v_gruppe_otvechayu_na')],
            'f' => [Group::APPROVED, Group::FREE, t('service.group_gate.rabotayu_v_gruppe_svobodno_sam')],
            default => [Group::REFUSED, Group::MENTION, t('service.group_gate.vyhozhu_iz_gruppy')],
        };
        $group = $this->groups->decide($callback->channel, $m[1], $status, $mode);
        $this->channel->answerCallback($callback->callbackId, $outcome);
        if ($group === null) {
            return;
        }
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('service.group_gate.gruppa'), $group->title, $outcome));
        }
        if ($status === Group::REFUSED) {
            $this->left[$group->chatId] = true;
            $this->channel->leave($group->chatId);
        }
        $this->logger->notice('Group decided', ['chat' => $group->chatId, 'status' => $status, 'mode' => $mode]);
    }

    /**
     * Someone opened a forum topic: it becomes a draft project of whoever opened it (the owner's projects have no
     * owner id). A stranger's topic is left alone.
     *
     * @param array<array-key, mixed> $opened
     */
    private function topicOpened(IncomingMessage $message, array $opened): void {
        $thread = MessageMeta::topicKey($message);
        $principal = $this->principals->findByIdentity($message->channel, $message->userId);
        if ($this->forum === null || $thread === '' || $principal === null || $principal->status !== PrincipalStatus::Active) {
            return;
        }
        /** @var mixed $name */
        $name = $opened['name'] ?? '';
        $this->forum->opened($message->channel, $message->chatId, $thread, is_string($name) ? $name : '', \OpenCCK\Domain\Project\Project::scopeOf($principal));
    }

    private function runOfReply(IncomingMessage $message): ?int {
        $replied = MessageMeta::repliedToAgent($message, $this->botId);
        return $replied === null ? null : $this->runMessages?->runOf($message->channel, $message->chatId, $replied);
    }

    /** A mention of the bot, a reply to its message, or a command addressed to it. */
    public function addressed(IncomingMessage $message): bool {
        /** @var mixed $reply */
        $reply = $message->raw['reply_to_message'] ?? null;
        /** @var mixed $sender */
        $sender = is_array($reply) ? ($reply['from'] ?? null) : null;
        /** @var mixed $from */
        $from = is_array($sender) ? ($sender['id'] ?? null) : null;
        if ((is_int($from) || is_string($from)) && (string) $from === $this->botId) {
            return true;
        }
        return $this->botUsername !== null && preg_match('~(^|[^\w])@' . preg_quote($this->botUsername, '~') . '\b~iu', $message->text) === 1;
    }

    private function strip(string $text): string {
        if ($this->botUsername === null) {
            return $text;
        }
        // "/cmd@bot args" becomes "/cmd args", a mention in the text is dropped
        $text = preg_replace('~^(/\w+)@' . preg_quote($this->botUsername, '~') . '\b~iu', '$1', $text) ?? $text;
        return trim(preg_replace('~(^|\s)@' . preg_quote($this->botUsername, '~') . '\b[,:]?~iu', '$1', $text) ?? $text);
    }

    private function remember(string $channel, string $chatId, string $thread, string $line): void {
        $this->talk->add($channel, $chatId, $thread, SecretMasker::mask($line));
    }

    private function card(string $chatId, string $title): void {
        $result = $this->channel->send(new OutgoingMessage($this->ownerChatId, sprintf(t('service.group_gate.menya_dobavili_v_gruppu_rabotat'), $title), buttons: [[
            new Button(t('service.group_gate.po_upominaniyu'), sprintf('gr:%s:m', $chatId)),
            new Button(t('service.group_gate.svobodno'), sprintf('gr:%s:f', $chatId)),
            new Button(t('service.group_gate.vyyti'), sprintf('gr:%s:r', $chatId)),
        ]]));
        if (!$result->success) {
            $this->logger->warning('Group card was not delivered', ['chat' => $chatId]);
        }
    }

    private static function title(IncomingMessage $message): string {
        /** @var mixed $chat */
        $chat = $message->raw['chat'] ?? null;
        /** @var mixed $title */
        $title = is_array($chat) ? ($chat['title'] ?? null) : null;
        return is_string($title) && $title !== '' ? mb_substr($title, 0, 200) : t('service.group_gate.bez_nazvaniya');
    }
}
