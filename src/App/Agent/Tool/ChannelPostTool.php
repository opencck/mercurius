<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DateTimeImmutable;
use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Group\ChannelPost;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Domain\Repository\ChannelRepository;

/**
 * Posts in channels where the bot is an admin. Subscribers see a post at once and it cannot be taken back, so
 * publishing, editing and deleting are irreversible: each waits for the owner's card with the whole post, and the owner
 * may allow an action in a channel for good ("publish in X" without asking, deletion still asked).
 *
 * spec: BRIEF.md#канал-паблик
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
        return new ToolDefinition(self::NAME, 'Посты в каналах, где бот — администратор. list — каналы и права; history — последние посты '
            . 'канала; publish (text), edit (message_id, text), delete (message_id) — каждое с подтверждением владельца, '
            . 'он увидит пост целиком. channel — название или номер канала; если доступен один канал, можно не указывать.', [
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
        $where = $target === null ? 'канал не найден' : sprintf('«%s»', $target->title);
        $text = Args::string($args, 'text') ?? '';
        return match (Args::string($args, 'action')) {
            'publish' => sprintf("Публикация в канал %s:\n\n%s", $where, $text),
            'edit' => sprintf("Правка поста #%d в канале %s, новый текст:\n\n%s", Args::int($args, 'message_id') ?? 0, $where, $text),
            'delete' => sprintf('Удаление поста #%d в канале %s', Args::int($args, 'message_id') ?? 0, $where),
            default => 'Каналы',
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
            return ToolResult::error('Канал не найден или их несколько: назови channel (list — список).');
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
                return ToolResult::error('Telegram не принял пост.');
            }
            $this->channels->savePost($target->channel, $target->chatId, $sent->messageId, $text, new DateTimeImmutable(), false);
            return ToolResult::ok(sprintf('Опубликовано в «%s»: пост #%s.', $target->title, $sent->messageId));
        }
        if ($action === 'edit') {
            if (!$this->channel->edit($target->chatId, $id, $text, ParseMode::Markdown)->success) {
                return ToolResult::error('Telegram не дал изменить пост.');
            }
            $this->channels->savePost($target->channel, $target->chatId, $id, $text, new DateTimeImmutable(), true);
            return ToolResult::ok(sprintf('Пост #%s в «%s» изменён.', $id, $target->title));
        }
        if (!$this->channel->delete($target->chatId, $id)) {
            return ToolResult::error('Telegram не дал удалить пост.');
        }
        $this->channels->deletePost($target->channel, $target->chatId, $id);
        return ToolResult::ok(sprintf('Пост #%s в «%s» удалён.', $id, $target->title));
    }

    private function list(): string {
        $all = $this->channels->all();
        if ($all === []) {
            return 'Каналов нет: бота нужно сделать администратором канала.';
        }
        return implode("\n", array_map(
            static fn(PublicChannel $c): string => sprintf('«%s» (%s): %s', $c->title, $c->chatId, $c->available ? $c->rights() : 'недоступен'),
            $all,
        ));
    }

    private function history(PublicChannel $target): string {
        $posts = $this->channels->posts($target->channel, $target->chatId, self::HISTORY);
        if ($posts === []) {
            return sprintf('В канале «%s» постов пока не видно.', $target->title);
        }
        return implode("\n\n", array_map(
            static fn(ChannelPost $p): string => sprintf('#%s %s%s: %s', $p->messageId, $p->postedAt->format('Y-m-d H:i'), $p->editedAt === null ? '' : ' (изменён)', $p->text),
            $posts,
        ));
    }

    /** Why the change cannot go, checked before the owner's card; null — it can. */
    private function refusal(PublicChannel $target, string $action, string $text, ?int $messageId): ?string {
        if (!in_array($action, self::CHANGES, true)) {
            return 'Неизвестное действие: ' . $action;
        }
        if (!$target->available) {
            return sprintf('В канале «%s» бот больше не администратор.', $target->title);
        }
        $allowed = match ($action) {
            'publish' => $target->canPost,
            'edit' => $target->canEdit,
            default => $target->canDelete,
        };
        if (!$allowed) {
            return sprintf('В канале «%s» у бота нет на это права (%s).', $target->title, $target->rights());
        }
        if ($action !== 'delete' && $text === '') {
            return 'Нужен текст поста: text.';
        }
        if ($action !== 'publish' && $messageId === null) {
            return 'Нужен номер поста: message_id.';
        }
        if ($text !== '' && SecretMasker::mask($text) !== $text) {
            return 'В пост не кладут секреты: уберите их из текста.';
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
