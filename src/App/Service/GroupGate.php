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

    /** @var array<string, list<string>> recent messages by chat */
    private array $recent = [];
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
    ) {}

    /**
     * What to do with a group message: null — nothing (silent); otherwise who speaks and the message for the agent.
     *
     * @return array{Principal, IncomingMessage}|null
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
        $context = $this->recent[$message->chatId] ?? [];
        $this->remember($message->chatId, sprintf('#%s %s%s: %s', $message->messageId, $speaker, $message->threadId === null ? '' : ' (тема #' . $message->threadId . ')', mb_substr(trim($message->text), 0, 500)));
        if ($group->status !== Group::APPROVED) {
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
        $meta = array_filter([MessageMeta::topic($message), MessageMeta::reply($message, $this->botId)]);
        $prefix = sprintf('[Группа «%s», пишет %s; это сообщение #%s%s.', $group->title, $speaker, $message->messageId, $meta === [] ? '' : '; ' . implode('; ', $meta));
        $prefix .= $group->mode === Group::FREE && !$addressed
            ? ' Тебя не звали: ответь, только если от тебя тут правда есть польза; иначе ответь ровно ' . self::SILENT . '.]'
            : ']';
        if ($context !== []) {
            $prefix .= "\n" . UntrustedWrapper::wrap('group-chat', "Последние сообщения группы:\n" . implode("\n", array_slice($context, -self::RECENT)));
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
        )];
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
            unset($this->left[$change->chatId], $this->recent[$change->chatId]);
            if ($group !== null && $group->status === Group::APPROVED) {
                $this->groups->decide($change->channel, $change->chatId, Group::PENDING, $group->mode);
                $this->channel->send(new OutgoingMessage($this->ownerChatId, sprintf('Меня убрали из группы «%s».', $group->title)));
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
            'm' => [Group::APPROVED, Group::MENTION, 'Работаю в группе: отвечаю на упоминание и ответ мне.'],
            'f' => [Group::APPROVED, Group::FREE, 'Работаю в группе свободно: сам решаю, когда ответить.'],
            default => [Group::REFUSED, Group::MENTION, 'Выхожу из группы.'],
        };
        $group = $this->groups->decide($callback->channel, $m[1], $status, $mode);
        $this->channel->answerCallback($callback->callbackId, $outcome);
        if ($group === null) {
            return;
        }
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, sprintf("Группа «%s»\n\n%s", $group->title, $outcome));
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

    private function remember(string $chatId, string $line): void {
        $this->recent[$chatId][] = SecretMasker::mask($line);
        if (count($this->recent[$chatId]) > self::RECENT) {
            $this->recent[$chatId] = array_slice($this->recent[$chatId], -self::RECENT);
        }
    }

    private function card(string $chatId, string $title): void {
        $result = $this->channel->send(new OutgoingMessage($this->ownerChatId, sprintf("Меня добавили в группу «%s». Работать там?\n\nПо упоминанию — отвечаю, когда обращаются ко мне. Свободно — сам решаю, когда вмешаться.", $title), buttons: [[
            new Button('По упоминанию', sprintf('gr:%s:m', $chatId)),
            new Button('Свободно', sprintf('gr:%s:f', $chatId)),
            new Button('Выйти', sprintf('gr:%s:r', $chatId)),
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
        return is_string($title) && $title !== '' ? mb_substr($title, 0, 200) : 'без названия';
    }
}
