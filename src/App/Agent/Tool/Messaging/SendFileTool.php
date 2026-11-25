<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\MediaKind;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Repository\ConversationRepository;

use function Mercurius\I18n\t;

/**
 * A file the agent made in its sandbox home goes to the chat it was asked in (the same forum topic) or to a participant,
 * as what it is: a photo, audio, video, a voice message, an animation or a document — by `as`, or guessed by the name.
 * To this chat or to the owner it is an answer; to anyone else it waits for the owner's card, granted per person.
 *
 * spec: brief/channels.md#личные-сообщения-участникам — not only text: everything the messenger carries
 * ref: references/hermes-agent/tools/send_message_tool.py@e96221fa:L490-L541 (_send_live_adapter_media) — media go
 *      to the target through the platform's adapter by their type; here the type is chosen or guessed by extension
 */
final class SendFileTool extends SandboxTool implements GrantKeyed {
    public const NAME = 'send_file';
    /** The sandbox gives a file whole up to this; Telegram takes documents up to 50 MB from a bot. */
    public const MAX_BYTES = 20_000_000;
    private const KINDS = [
        'jpg' => 'photo', 'jpeg' => 'photo', 'png' => 'photo', 'webp' => 'photo',
        'mp3' => 'audio', 'm4a' => 'audio', 'flac' => 'audio', 'wav' => 'audio',
        'mp4' => 'video', 'mov' => 'video', 'webm' => 'video',
        'oga' => 'voice', 'opus' => 'voice',
        'gif' => 'animation',
    ];

    public function __construct(
        Sandbox $sandbox,
        private readonly Channel $channel,
        private readonly ConversationRepository $conversations,
        private readonly ?Recipients $recipients = null,
        /** @var (\Closure(int, string, string): bool)|null a file sent in a task of another agent goes with the answer */
        private readonly ?\Closure $answerTask = null,
    ) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.send_file.about'), [
            'type' => 'object',
            'properties' => [
                'area' => self::areaProperty(),
                'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'description' => t('tool.send_file.path')],
                'caption' => ['type' => 'string', 'maxLength' => 1000],
                'as' => ['type' => 'string', 'enum' => array_map(static fn(MediaKind $k): string => $k->value, MediaKind::cases()), 'description' => t('tool.send_file.as')],
                'to' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.send_content.to')],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return $this->recipients?->danger(Args::string($args, 'to')) ?? DangerClass::Write;
    }

    public function grantKey(array $args): ?string {
        return $this->recipients?->grantKey(Args::string($args, 'to'));
    }

    public function describe(array $args): string {
        return sprintf(t('tool.send_file.describe'), Args::string($args, 'path') ?? '', $this->recipients?->name(Args::string($args, 'to')) ?? '');
    }

    /** The kind by `as`, else by the extension; a document when nothing fits. */
    public static function kind(string $path, ?string $as): MediaKind {
        $named = $as === null ? null : MediaKind::tryFrom($as);
        if ($named !== null) {
            return $named;
        }
        $guess = self::KINDS[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        return $guess === null ? MediaKind::Document : MediaKind::from($guess);
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $to = Args::string($args, 'to');
        // spec: brief/internet.md#между-агентами — send_file without `to` in a conversation of an A2A task is the answer
        if (($to === null || $to === '') && $this->answerTask !== null && $this->conversations->meta($context->conversationId)?->channel === 'a2a') {
            $path = Args::string($args, 'path') ?? '';
            $bytes = $this->sandbox->fetch($this->holder($args, $context), $limits, $path, self::MAX_BYTES, $context->cancellation);
            $name = basename(str_replace('\\', '/', $path)) ?: 'file';
            return ($this->answerTask)($context->runId, $name, $bytes)
                ? ToolResult::ok(sprintf(t('tool.send_file.with_answer'), $name, strlen($bytes)))
                : ToolResult::error(t('tool.send_file.answer_failed'));
        }
        if ($this->recipients !== null) {
            $where = $this->recipients->where($to, $context, $this->conversations);
        } else {
            $meta = $this->conversations->meta($context->conversationId);
            $where = $meta === null ? t('tool.send_file.no_chat') : [$meta->chatId, $meta->threadId === '' ? null : $meta->threadId];
        }
        if (is_string($where)) {
            return ToolResult::error($where);
        }
        [$chat, $thread] = $where;
        $path = Args::string($args, 'path') ?? '';
        $bytes = $this->sandbox->fetch($this->holder($args, $context), $limits, $path, self::MAX_BYTES, $context->cancellation);
        $name = basename(str_replace('\\', '/', $path));
        $name = $name === '' ? 'file' : $name;
        $caption = trim(Args::string($args, 'caption') ?? '');
        $kind = self::kind($name, Args::string($args, 'as'));
        $sent = $this->channel->sendMedia($chat, $kind, $name, $bytes, $caption === '' ? null : mb_substr($caption, 0, 1000), $thread);
        if (!$sent->success) {
            return ToolResult::error(sprintf(t('tool.send_file.not_sent'), $name, $sent->error ?? ''));
        }
        $this->recipients?->sent($to, sprintf(t('tool.send_message.with_files'), $name) . ($caption === '' ? '' : ' — ' . $caption), $context->principal);
        return ToolResult::ok(sprintf(t('tool.send_file.sent_as'), $name, strlen($bytes), $kind->value));
    }
}
