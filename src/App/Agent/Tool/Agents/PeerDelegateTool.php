<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Files\SandboxFiles;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PeerRepository;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * Gives a task to a neighbouring agent over A2A and returns at once; the answer comes to this chat later, as data.
 *
 * spec: BRIEF.md#сеть-агентов — a remote agent as a worker; remote delegation is always in the background
 */
final class PeerDelegateTool implements Tool, ReadsOutside {
    public const NAME = 'peer_delegate';

    public function __construct(
        private readonly PeerNetwork $network,
        private readonly PeerRepository $peers,
        private readonly ConversationRepository $conversations,
        /** files to attach come from the participant's sandbox; null — text only */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null,
    ) {}

    public function definition(): ToolDefinition {
        $names = $this->network->names();
        return new ToolDefinition(self::NAME, t('tool.peer_delegate.otdaet_zadachu_sosednemu_agentu_drugomu')
            . t('tool.peer_delegate.otvet_pridet_v_etot_chat')
            . t('tool.peer_delegate.bez_peer_spisok_sosedey'), [
            'type' => 'object',
            'properties' => [
                'peer' => $names === [] ? ['type' => 'string', 'maxLength' => 40] : ['type' => 'string', 'enum' => $names],
                'task' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 8000],
                'files' => ['type' => 'array', 'maxItems' => SandboxFiles::COUNT_MAX, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024], 'description' => t('tool.peer_delegate.files')],
                'reply_to' => ['type' => 'integer', 'minimum' => 1, 'description' => t('tool.peer_call.reply_to')],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'peer') === null ? DangerClass::Read : DangerClass::Write;
    }

    /** spec: BRIEF.md#подтверждения-и-запреты — the answer of another agent is outside text */
    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 45;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $peer = Args::string($args, 'peer');
        $task = trim(Args::string($args, 'task') ?? '');
        if ($peer === null) {
            // a task without a neighbour is a mistake, not a question who is there: said so, or the model calls again
            return $task === '' ? ToolResult::ok($this->network->text()) : ToolResult::error(t('tool.peer_delegate.no_peer') . "\n\n" . $this->network->text());
        }
        if ($task === '') {
            return ToolResult::error(t('tool.peer_delegate.nuzhna_zadacha_task'));
        }
        $meta = $this->conversations->meta($context->conversationId) ?? throw new DomainException(t('tool.peer_delegate.beseda_ne_naydena'));
        // A run that is itself a task from a neighbour carries its chain on.
        $parent = $meta->channel === A2aServer::CHANNEL ? $this->peers->taskByRun($context->runId) : null;
        $contextId = PeerCallTool::replyContext($this->peers, $context, $peer, Args::int($args, 'reply_to'));
        if ($contextId === false) {
            return ToolResult::error(t('tool.peer_call.bad_reply_to'));
        }
        try {
            // spec: BRIEF.md#между-агентами — files of the sandbox go with the task
            $files = SandboxFiles::read($this->sandbox, $args, $context);
            $out = $this->network->delegate($context->principal, $peer, $task, $meta->channel, $meta->chatId, $parent === null ? 0 : $parent->depth, $parent === null ? [] : $parent->path, $files, $context->runId, false, $contextId);
        } catch (DomainException | RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf(t('tool.peer_delegate.zadacha_otdana_sosedu_otvet_pridet'), $out->id, $out->peer, $out->remoteId)
            . ($files === [] ? '' : sprintf(t('tool.peer_delegate.with_files'), implode(', ', array_column($files, 'name')))));
    }
}
