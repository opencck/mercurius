<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\ContactChannel;
use Mercurius\Messenger\LocationChannel;
use Mercurius\Messenger\PollChannel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;

use function Mercurius\I18n\t;

/**
 * What the messenger carries besides text and files: a location, a contact, a poll, an article (a title and formatted
 * text). To this chat and topic, or to a participant (then with the owner's card, granted per person).
 *
 * spec: brief/channels.md#личные-сообщения-участникам — not only text: everything the messenger carries
 */
final class SendContentTool implements Tool, GrantKeyed {
    public const NAME = 'send_content';
    public const KINDS = ['location', 'contact', 'poll', 'article'];

    public function __construct(
        private readonly Channel $channel,
        private readonly ConversationRepository $conversations,
        private readonly Recipients $recipients,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.send_content.about'), [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => self::KINDS],
                'to' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.send_content.to')],
                'latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
                'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
                'phone' => ['type' => 'string', 'maxLength' => 32],
                'first_name' => ['type' => 'string', 'maxLength' => 64],
                'last_name' => ['type' => 'string', 'maxLength' => 64],
                'question' => ['type' => 'string', 'maxLength' => 300],
                'options' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]],
                'anonymous' => ['type' => 'boolean'],
                'multiple' => ['type' => 'boolean'],
                'title' => ['type' => 'string', 'maxLength' => 200],
                'text' => ['type' => 'string', 'maxLength' => 20_000, 'description' => t('tool.send_content.text')],
            ],
            'required' => ['kind'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return $this->recipients->danger(Args::string($args, 'to'));
    }

    public function grantKey(array $args): ?string {
        return $this->recipients->grantKey(Args::string($args, 'to'));
    }

    public function describe(array $args): string {
        return sprintf(t('tool.send_content.describe'), Args::string($args, 'kind') ?? '', $this->recipients->name(Args::string($args, 'to')));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $where = $this->recipients->where(Args::string($args, 'to'), $context, $this->conversations);
        if (is_string($where)) {
            return ToolResult::error($where);
        }
        [$chat, $thread] = $where;
        $kind = Args::string($args, 'kind') ?? '';
        $sent = match ($kind) {
            'location' => $this->location($args, $chat, $thread),
            'contact' => $this->contact($args, $chat, $thread),
            'poll' => $this->poll($args, $chat, $thread),
            'article' => $this->article($args, $chat, $thread),
            default => t('tool.send_content.bad_kind'),
        };
        if (is_string($sent)) {
            return ToolResult::error($sent);
        }
        if ($sent->success) {
            $this->recipients->sent(Args::string($args, 'to'), $kind . ': ' . (Args::string($args, 'title') ?? Args::string($args, 'question') ?? ''), $context->principal);
        }
        return $sent->success
            ? ToolResult::ok(sprintf(t('tool.send_content.sent'), $kind))
            : ToolResult::error(sprintf(t('tool.send_content.not_sent'), $kind, $sent->error ?? ''));
    }

    /** @param array<string, mixed> $args */
    private function location(array $args, string $chat, ?string $thread): \Mercurius\Messenger\SendResult|string {
        $lat = self::number($args, 'latitude');
        $lon = self::number($args, 'longitude');
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
            return t('tool.send_content.bad_location');
        }
        return $this->channel instanceof LocationChannel ? $this->channel->sendLocation($chat, $lat, $lon, $thread) : t('tool.send_content.unsupported');
    }

    /** @param array<string, mixed> $args */
    private function contact(array $args, string $chat, ?string $thread): \Mercurius\Messenger\SendResult|string {
        $phone = trim(Args::string($args, 'phone') ?? '');
        $first = trim(Args::string($args, 'first_name') ?? '');
        if ($phone === '' || $first === '') {
            return t('tool.send_content.bad_contact');
        }
        $last = trim(Args::string($args, 'last_name') ?? '');
        return $this->channel instanceof ContactChannel ? $this->channel->sendContact($chat, $phone, $first, $last === '' ? null : $last, $thread) : t('tool.send_content.unsupported');
    }

    /** @param array<string, mixed> $args */
    private function poll(array $args, string $chat, ?string $thread): \Mercurius\Messenger\SendResult|string {
        $question = trim(Args::string($args, 'question') ?? '');
        /** @var mixed $raw */
        $raw = $args['options'] ?? [];
        $options = is_array($raw) ? array_values(array_filter(array_map(static fn(mixed $o): string => is_string($o) ? trim($o) : '', $raw), static fn(string $o): bool => $o !== '')) : [];
        if ($question === '' || count($options) < 2 || count($options) > 10) {
            return t('tool.send_content.bad_poll');
        }
        return $this->channel instanceof PollChannel ? $this->channel->sendPoll($chat, $question, $options, ($args['anonymous'] ?? true) !== false, ($args['multiple'] ?? false) === true, $thread) : t('tool.send_content.unsupported');
    }

    /** @param array<string, mixed> $args */
    private function article(array $args, string $chat, ?string $thread): \Mercurius\Messenger\SendResult|string {
        $text = trim(Args::string($args, 'text') ?? '');
        if ($text === '') {
            return t('tool.send_content.bad_article');
        }
        $title = trim(Args::string($args, 'title') ?? '');
        // Markdown of the agent becomes Telegram HTML in the channel; a long article is split there
        return $this->channel->send(new OutgoingMessage($chat, ($title === '' ? '' : '**' . $title . "**\n\n") . $text, parseMode: ParseMode::Markdown, threadId: $thread));
    }

    /** @param array<string, mixed> $args */
    private static function number(array $args, string $key): ?float {
        /** @var mixed $value */
        $value = $args[$key] ?? null;
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
