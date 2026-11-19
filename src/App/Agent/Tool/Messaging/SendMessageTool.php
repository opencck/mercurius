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
        /** files to attach come from the sender's sandbox home; null — text only */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null,
        /** another agent — a neighbour or one connected here — is written to over A2A; null — people only */
        private readonly ?\OpenCCK\App\Peer\PeerNetwork $network = null,
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
        return $recipient instanceof Principal && $recipient->role === Role::owner() ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $recipient = $this->recipient($args);
        return $recipient instanceof Principal && $recipient->role !== Role::owner() ? 'message:' . $recipient->id : null;
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
        // spec: BRIEF.md#личные-сообщения-участникам — not only text and not only people: files, and other agents
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
     * @return Principal|string the recipient, or why there is none
     */
    private function recipient(array $args): Principal|string {
        return (new Recipients($this->principals, $this->channelName))->find(Args::string($args, 'to') ?? '');
    }
}
