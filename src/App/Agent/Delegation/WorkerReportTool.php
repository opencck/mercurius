<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use Mercurius\Llm\Role;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;

use function Mercurius\I18n\t;

/**
 * The full answer of a worker whose report was cut: only of the current run's workers, or of a delegation of the same
 * conversation (a background report comes as a new run).
 *
 * spec: brief/orchestration.md#часть-1--синхронное-делегирование — report
 * ref: references/hermes-agent/tools/delegate_tool_results.py@e96221fa:L254-L309 (_apply_summary_budget) — an
 *      over-cap summary keeps its head and tail and points to the full text the parent can read; here a tool instead
 *      of a file, so it works without a sandbox
 */
final class WorkerReportTool implements Tool {
    public const NAME = 'worker_report';

    public function __construct(
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('delegation.worker_report_tool.polnyy_itog_vorkera_esli_v'), [
            'type' => 'object',
            'properties' => ['run' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['run'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    /** A worker retells what it read: data, not instructions. */
    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = isset($args['run']) && is_int($args['run']) ? $args['run'] : 0;
        $run = $this->runs->find($id);
        $parentId = $run?->parentRunId;
        $parent = $parentId === null ? null : $this->runs->find($parentId);
        // In a group one conversation serves several participants: the run must also be theirs (or the owner asks).
        $theirs = $parent !== null && ($parent->principalId === $context->principal->id || $context->principal->isOwner());
        if ($run === null || $parent === null || !$theirs || ($parent->id !== $context->runId && $parent->conversationId !== $context->conversationId)) {
            return ToolResult::error(t('delegation.worker_report_tool.takogo_zapuska_vorkera_u_etoy'));
        }
        $messages = $this->transcript->runMessages($run->id);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->role === Role::Assistant && $messages[$i]->toolCalls === [] && trim($messages[$i]->content) !== '') {
                return ToolResult::ok(trim($messages[$i]->content));
            }
        }
        return ToolResult::error(t('delegation.worker_report_tool.itoga_u_etogo_zapuska_net'));
    }
}
