<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * Stops a task this agent gave a neighbour: the neighbour gets CancelTask, the task ends as canceled here.
 *
 * spec: BRIEF.md#сеть-агентов — the leader cancels a task
 * ref: references/hermes-agent/plugins/platforms/a2a/adapter.py@e96221fa:L802-L812 (CancelTask) — the side that does the
 *      work marks the task canceled; hermes has no tool to send it, here the leader does
 */
final class PeerCancelTool implements Tool {
    public const NAME = 'peer_cancel';

    public function __construct(private readonly PeerNetwork $network) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.peer_cancel.about'), [
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'integer', 'minimum' => 1, 'description' => t('tool.peer_cancel.task')],
            ],
            'required' => ['task'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = Args::int($args, 'task') ?? 0;
        $why = $this->network->cancel($context->principal, $id);
        return $why === null ? ToolResult::ok(sprintf(t('peer.peer_network.canceled'), $id)) : ToolResult::error($why);
    }
}
