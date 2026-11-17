<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\MessageRelayRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * A personal message to a participant: to their private chat with the bot, on someone's request. The recipient sees
 * on whose request the agent writes. With expect_reply their next message in that chat goes back to the conversation
 * that asked, as outside data. Writing to the owner is a write; to anyone else it waits for the owner's card and can be
 * granted per person.
 *
 * spec: BRIEF.md#личные-сообщения-участникам
 * ref: references/hermes-agent/tools/send_message_tool.py@e96221fa:L208-L304 (_handle_send), L429-L440
 *      (_mirror_sent_message) — send to a resolved target and keep the conversation aware of what was sent; hermes
 *      mirrors the text into the target's session, here the recipient's answer is relayed back to the asking one
 */
final class SendMessageTool implements Tool, GrantKeyed {
    public const NAME = 'send_message';
    private const TEXT_MAX = 4000;

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly MessageRelayRepository $relays,
        private readonly string $channelName = 'telegram',
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.send_message.about'), [
            'type' => 'object',
            'properties' => [
                'to' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => t('tool.send_message.to')],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::TEXT_MAX],
                'expect_reply' => ['type' => 'boolean', 'description' => t('tool.send_message.expect_reply')],
            ],
            'required' => ['to', 'text'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        $recipient = $this->recipient($args);
        return $recipient instanceof Principal && $recipient->role === Role::Owner ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $recipient = $this->recipient($args);
        return $recipient instanceof Principal && $recipient->role !== Role::Owner ? 'message:' . $recipient->id : null;
    }

    public function describe(array $args): string {
        $recipient = $this->recipient($args);
        $name = $recipient instanceof Principal ? ($recipient->displayName ?? '#' . $recipient->id) : (Args::string($args, 'to') ?? '');
        return sprintf(t('tool.send_message.describe'), $name, mb_substr(Args::string($args, 'text') ?? '', 0, 500));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $recipient = $this->recipient($args);
        if (is_string($recipient)) {
            return ToolResult::error($recipient);
        }
        $chatId = $this->principals->externalId($recipient->id, $this->channelName);
        if ($chatId === null) {
            return ToolResult::error(sprintf(t('tool.send_message.no_chat'), $recipient->displayName ?? '#' . $recipient->id));
        }
        $text = Args::string($args, 'text') ?? '';
        // the recipient sees on whose request the agent writes, unless it writes to the one who asked
        $shown = $recipient->id === $context->principal->id
            ? $text
            : sprintf(t('tool.send_message.header'), $context->principal->displayName ?? '#' . $context->principal->id) . $text;
        $sent = $this->channel->send(new OutgoingMessage($chatId, $shown));
        if (!$sent->success) {
            return ToolResult::error(sprintf(t('tool.send_message.not_sent'), $recipient->displayName ?? '#' . $recipient->id));
        }
        $waits = ($args['expect_reply'] ?? false) === true && $recipient->id !== $context->principal->id;
        if ($waits) {
            $this->relays->create($this->channelName, $chatId, $recipient->id, $context->conversationId, $context->principal->id, $context->runId, mb_substr($text, 0, 1000));
        }
        return ToolResult::ok(sprintf(t($waits ? 'tool.send_message.sent_waiting' : 'tool.send_message.sent'), $recipient->displayName ?? '#' . $recipient->id));
    }

    /**
     * The active person the call names: "owner", an id, or a name (exact first, then a part of it).
     *
     * @param array<string, mixed> $args
     * @return Principal|string the recipient, or why there is none
     */
    private function recipient(array $args): Principal|string {
        $to = trim(Args::string($args, 'to') ?? '');
        $people = array_values(array_filter(
            $this->principals->all(),
            static fn(Principal $p): bool => $p->kind === PrincipalKind::Human && $p->status === PrincipalStatus::Active,
        ));
        $key = mb_strtolower(ltrim($to, '@#'));
        if (in_array($key, ['owner', 'владелец', 'владельцу'], true)) {
            $owner = array_values(array_filter($people, static fn(Principal $p): bool => $p->role === Role::Owner));
            return $owner[0] ?? t('tool.send_message.no_owner');
        }
        if (ctype_digit($key)) {
            foreach ($people as $p) {
                if ($p->id === (int) $key) {
                    return $p;
                }
            }
        }
        $exact = array_values(array_filter($people, static fn(Principal $p): bool => mb_strtolower($p->displayName ?? '') === $key));
        $found = $exact !== [] ? $exact : array_values(array_filter(
            $people,
            static fn(Principal $p): bool => $key !== '' && str_contains(mb_strtolower($p->displayName ?? ''), $key),
        ));
        if (count($found) === 1) {
            return $found[0];
        }
        $names = implode(', ', array_map(static fn(Principal $p): string => sprintf('%s (#%d)', $p->displayName ?? '—', $p->id), $found === [] ? $people : $found));
        return sprintf(t($found === [] ? 'tool.send_message.unknown' : 'tool.send_message.ambiguous'), $to, $names);
    }
}
