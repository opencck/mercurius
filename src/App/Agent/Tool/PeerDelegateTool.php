<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PeerRepository;
use RuntimeException;

/**
 * Gives a task to a neighbouring agent over A2A and returns at once; the answer comes to this chat later, as data.
 *
 * spec: BRIEF.md#сеть-агентов — a remote agent as a worker; remote delegation is always in the background
 */
final class PeerDelegateTool implements Tool {
    public const NAME = 'peer_delegate';

    public function __construct(
        private readonly PeerNetwork $network,
        private readonly PeerRepository $peers,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        $names = $this->network->names();
        return new ToolDefinition(self::NAME, 'Отдаёт задачу соседнему агенту (другому экземпляру Меркурия или агенту A2A) и сразу возвращается; '
            . 'ответ придёт в этот чат позже. Задачу пиши полностью, без секретов и личных сведений — у соседа своя память и свои права. '
            . 'Без peer — список соседей.', [
            'type' => 'object',
            'properties' => [
                'peer' => $names === [] ? ['type' => 'string', 'maxLength' => 40] : ['type' => 'string', 'enum' => $names],
                'task' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 8000],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'peer') === null ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 45;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $peer = Args::string($args, 'peer');
        if ($peer === null) {
            return ToolResult::ok($this->network->text());
        }
        $task = trim(Args::string($args, 'task') ?? '');
        if ($task === '') {
            return ToolResult::error('Нужна задача: task.');
        }
        $meta = $this->conversations->meta($context->conversationId) ?? throw new DomainException('Беседа не найдена.');
        // A run that is itself a task from a neighbour carries its chain on.
        $parent = $meta->channel === A2aServer::CHANNEL ? $this->peers->taskByRun($context->runId) : null;
        try {
            $out = $this->network->delegate($context->principal, $peer, $task, $meta->channel, $meta->chatId, $parent === null ? 0 : $parent->depth, $parent === null ? [] : $parent->path);
        } catch (DomainException | RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf('Задача #%d отдана соседу «%s» (%s). Ответ придёт в этот чат.', $out->id, $out->peer, $out->remoteId));
    }
}
