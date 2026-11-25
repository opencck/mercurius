<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Files\SandboxFiles;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\MessageRelayRepository;
use OpenCCK\Domain\Repository\MemberRepository;

use function Mercurius\I18n\t;

/**
 * A personal message to a participant: to their private chat with the bot, on someone's request. The recipient sees
 * on whose request the agent writes. With expect_reply their next message in that chat goes back to the conversation
 * that asked, as outside data. Writing to the owner is a write; to anyone else it waits for the owner's card and can be
 * granted per person.
 *
 * spec: brief/channels.md#личные-сообщения-участникам
 * ref: references/hermes-agent/tools/send_message_tool.py@e96221fa:L208-L304 (_handle_send), L429-L440
 *      (_mirror_sent_message) — send to a resolved target and keep the conversation aware of what was sent; hermes
 *      mirrors the text into the target's session, here the recipient's answer is relayed back to the asking one
 */
final class SendMessageTool implements Tool, GrantKeyed {
    public const NAME = 'send_message';
    private const TEXT_MAX = 4000;

    public function __construct(
        private readonly MemberRepository $principals,
        private readonly Channel $channel,
        private readonly MessageRelayRepository $relays,
        private readonly string $channelName,
        /** files to attach come from the sender's sandbox home; null — text only */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null,
        /** another agent — a neighbour or one connected here — is written to over A2A; null — people only */
        private readonly ?\OpenCCK\App\Peer\PeerNetwork $network = null,
        /** knows the places besides people: approved groups, chats and topics of projects; null — people only */
        private readonly ?Recipients $places = null,
    ) {}

    /** Files at most per message, and their size in all. */
    public const FILES_MAX = SandboxFiles::COUNT_MAX;
    public const BYTES_MAX = SandboxFiles::BYTES_MAX;

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.send_message.about'), [
            'type' => 'object',
            'properties' => [
                'to' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => t('tool.send_message.to')],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::TEXT_MAX],
                'expect_reply' => ['type' => 'boolean', 'description' => t('tool.send_message.expect_reply')],
                'files' => ['type' => 'array', 'maxItems' => self::FILES_MAX, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024], 'description' => t('tool.send_message.files')],
            ],
            'required' => ['to', 'text'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        if ($this->agent($args) !== null) {
            // a task for another agent, as peer_delegate gives it
            return DangerClass::Write;
        }
        $recipient = $this->recipient($args);
        return $recipient instanceof Member && $recipient->role === Role::owner() ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $recipient = $this->recipient($args);
        if (is_string($recipient)) {
            return $this->agent($args) === null ? $this->places?->grantKey(Args::string($args, 'to')) : null;
        }
        return $recipient->role !== Role::owner() ? 'message:' . $recipient->id : null;
    }

    public function describe(array $args): string {
        $recipient = $this->recipient($args);
        $place = is_string($recipient) && $this->agent($args) === null ? $this->places?->place(Args::string($args, 'to')) : null;
        if (is_array($place)) {
            return sprintf(t('tool.send_message.describe_place'), $place['label'], mb_substr(Args::string($args, 'text') ?? '', 0, 500));
        }
        $name = $recipient instanceof Member ? ($recipient->displayName ?? '#' . $recipient->id) : (Args::string($args, 'to') ?? '');
        return sprintf(t('tool.send_message.describe'), $name, mb_substr(Args::string($args, 'text') ?? '', 0, 500));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        // spec: brief/channels.md#личные-сообщения-участникам — not only text and not only people: files, and other agents
        try {
            $files = SandboxFiles::read($this->sandbox, $args, $context);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }
        $agent = $this->agent($args);
        if ($agent !== null && $this->network !== null) {
            try {
                $task = $this->network->delegate($context->principal, $agent, Args::string($args, 'text') ?? '', $this->channelName, $this->principals->externalId($context->principal->id, $this->channelName) ?? '', 0, [], $files);
            } catch (\DomainException $e) {
                return ToolResult::error($e->getMessage());
            }
            return ToolResult::ok(sprintf(t('tool.send_message.to_agent'), $agent, $task->id, count($files)));
        }
        $recipient = $this->recipient($args);
        if (is_string($recipient)) {
            $place = $this->places?->place(Args::string($args, 'to'));
            if (is_array($place)) {
                return $this->toPlace($place, Args::string($args, 'text') ?? '', $files, $context);
            }
            $known = $this->places?->places() ?? [];
            return ToolResult::error($place ?? ($known === [] ? $recipient : $recipient . ' ' . sprintf(t('tool.send_message.places'), implode('; ', $known))));
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
        foreach ($files as $file) {
            $attached = $this->channel->sendFile($chatId, $file['name'], $file['bytes']);
            if (!$attached->success) {
                return ToolResult::error(sprintf(t('tool.send_file.not_sent'), $file['name'], $attached->error ?? ''));
            }
        }
        // an answer of someone else comes back by default; the owner's private chat is their own conversation
        $waits = ($args['expect_reply'] ?? true) !== false && $recipient->id !== $context->principal->id && $recipient->role !== Role::owner();
        if ($waits) {
            $this->relays->create($this->channelName, $chatId, $recipient->id, $context->conversationId, $context->principal->id, $context->runId, mb_substr($text, 0, 1000));
        }
        return ToolResult::ok(sprintf(t($waits ? 'tool.send_message.sent_waiting' : 'tool.send_message.sent'), $recipient->displayName ?? '#' . $recipient->id));
    }

    /**
     * A message into a group or a topic: everyone there reads it, so nobody's answer is waited for — what people say
     * there comes as the messages of that chat. The owner speaks through the agent in their own name; a request of
     * anyone else is shown as theirs.
     *
     * spec: brief/channels.md#сообщение-в-группу-или-тему-по-просьбе
     *
     * @param array{chat: string, thread: ?string, label: string} $place
     * @param list<array{name: string, bytes: string}> $files
     */
    private function toPlace(array $place, string $text, array $files, ToolContext $context): ToolResult {
        $shown = $context->principal->role === Role::owner()
            ? $text
            : sprintf(t('tool.send_message.header'), $context->principal->displayName ?? '#' . $context->principal->id) . $text;
        $sent = $this->channel->send(new OutgoingMessage($place['chat'], $shown, threadId: $place['thread']));
        if (!$sent->success) {
            return ToolResult::error(sprintf(t('tool.send_message.place_not_sent'), $place['label'], $sent->error ?? ''));
        }
        foreach ($files as $file) {
            $attached = $this->channel->sendFile($place['chat'], $file['name'], $file['bytes'], null, $place['thread']);
            if (!$attached->success) {
                return ToolResult::error(sprintf(t('tool.send_file.not_sent'), $file['name'], $attached->error ?? ''));
            }
        }
        $this->places?->sent($place['label'], $files === [] ? $text : $text . "\n" . sprintf(t('tool.send_message.with_files'), implode(', ', array_column($files, 'name'))), $context->principal);
        return ToolResult::ok(sprintf(t('tool.send_message.sent_place'), $place['label']));
    }

    /**
     * The agent the call names, when it is not a person here: a neighbour or an agent connected to this one.
     *
     * @param array<string, mixed> $args
     */
    private function agent(array $args): ?string {
        $to = trim(Args::string($args, 'to') ?? '');
        if ($this->network === null || $to === '' || !in_array($to, $this->network->names(), true)) {
            return null;
        }
        return is_string($this->recipient($args)) ? $to : null;
    }

    /**
     * The active person the call names: "owner", an id, or a name (exact first, then a part of it).
     *
     * @param array<string, mixed> $args
     * @return Member|string the recipient, or why there is none
     */
    private function recipient(array $args): Member|string {
        return (new Recipients($this->principals, $this->channelName))->find(Args::string($args, 'to') ?? '');
    }
}
