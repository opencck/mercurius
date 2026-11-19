<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\LongRunning;
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
 * Gives a task to one neighbouring agent and waits for its answer, up to `wait` seconds; an answer later than that
 * comes to the chat, as for peer_delegate.
 *
 * spec: BRIEF.md#сеть-агентов — peer_call next to peer_delegate (decision В9)
 * ref: references/hermes-agent/plugins/platforms/a2a/tools.py@e96221fa:L172-L196 (a2a_call) — call one agent and wait;
 *      the answer is the result of the call, a question of the agent comes back as such
 */
final class PeerCallTool implements Tool, ReadsOutside, LongRunning {
    public const NAME = 'peer_call';
    public const WAIT_MAX = 600;

    public function __construct(
        private readonly PeerNetwork $network,
        private readonly PeerRepository $peers,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        $names = $this->network->names();
        return new ToolDefinition(self::NAME, t('tool.peer_call.about'), [
            'type' => 'object',
            'properties' => [
                'peer' => $names === [] ? ['type' => 'string', 'maxLength' => 40] : ['type' => 'string', 'enum' => $names],
                'task' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 8000],
                'wait' => ['type' => 'integer', 'minimum' => 5, 'maximum' => self::WAIT_MAX, 'description' => t('tool.peer_call.wait')],
            ],
            'required' => ['peer', 'task'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    /** spec: BRIEF.md#подтверждения-и-запреты — the answer of another agent is outside text */
    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return self::WAIT_MAX + 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $peer = Args::string($args, 'peer') ?? '';
        $task = trim(Args::string($args, 'task') ?? '');
        $wait = max(5, min(self::WAIT_MAX, Args::int($args, 'wait') ?? 120));
        $result = self::call($this->network, $this->peers, $this->conversations, $context, $peer, $task, (float) $wait);
        return is_string($result) ? ToolResult::error($result) : ToolResult::ok($result['text']);
    }

    /**
     * Gives the task and waits; the text for the model, or why the task was not given.
     *
     * @return array{text: string, ended: bool}|string
     */
    public static function call(PeerNetwork $network, PeerRepository $peers, ConversationRepository $conversations, ToolContext $context, string $peer, string $task, float $wait): array|string {
        if ($peer === '' || $task === '') {
            return t('tool.peer_call.needs');
        }
        $meta = $conversations->meta($context->conversationId);
        if ($meta === null) {
            return t('tool.peer_delegate.beseda_ne_naydena');
        }
        // a run that is itself a task from a neighbour carries its chain on
        $parent = $meta->channel === A2aServer::CHANNEL ? $peers->taskByRun($context->runId) : null;
        try {
            $out = $network->delegate($context->principal, $peer, $task, $meta->channel, $meta->chatId, $parent === null ? 0 : $parent->depth, $parent === null ? [] : $parent->path, [], $context->runId, true);
        } catch (DomainException | RuntimeException $e) {
            return $e->getMessage();
        }
        $outcome = $network->outcome($out, $wait, $context->cancellation);
        if ($outcome === null) {
            return ['text' => sprintf(t('tool.peer_call.later'), $peer, (int) $wait, $out->id), 'ended' => false];
        }
        return ['text' => sprintf(t('tool.peer_call.answer'), $peer, $outcome['label'], $outcome['answer'] === '' ? t('peer.peer_network.bez_teksta') : $outcome['answer']), 'ended' => true];
    }
}
