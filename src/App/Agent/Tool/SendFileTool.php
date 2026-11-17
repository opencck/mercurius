<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Repository\ConversationRepository;

use function Mercurius\I18n\t;

/**
 * A file the agent made in its sandbox home (a presentation, a table, a report, a picture) goes to the chat it was
 * asked in, as a document, into the same forum topic. It is an answer in the same chat, so it waits for nobody.
 *
 * spec: BRIEF.md#песочница — files from the sandbox reach the chat
 * ref: references/hermes-agent/tools/send_message_tool.py@e96221fa:L490-L541 (_send_live_adapter_media) — media go
 *      to the same target as the text, through the platform's adapter; here one document per call from the sandbox
 */
final class SendFileTool extends SandboxTool {
    public const NAME = 'send_file';
    /** Telegram takes documents up to 50 MB from a bot; the sandbox gives a file whole up to this. */
    public const MAX_BYTES = 5_000_000;

    public function __construct(
        Sandbox $sandbox,
        private readonly Channel $channel,
        private readonly ConversationRepository $conversations,
    ) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.send_file.about'), [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'description' => t('tool.send_file.path')],
                'caption' => ['type' => 'string', 'maxLength' => 1000],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error(t('tool.send_file.no_chat'));
        }
        $path = Args::string($args, 'path') ?? '';
        $bytes = $this->sandbox->fetch($context->principal->id, $limits, $path, self::MAX_BYTES, $context->cancellation);
        $name = basename(str_replace('\\', '/', $path));
        $caption = trim(Args::string($args, 'caption') ?? '');
        $sent = $this->channel->sendFile($meta->chatId, $name === '' ? 'file' : $name, $bytes, $caption === '' ? null : mb_substr($caption, 0, 1000), $meta->threadId === '' ? null : $meta->threadId);
        if (!$sent->success) {
            return ToolResult::error(sprintf(t('tool.send_file.not_sent'), $name, $sent->error ?? ''));
        }
        return ToolResult::ok(sprintf(t('tool.send_file.sent'), $name, strlen($bytes)));
    }
}
