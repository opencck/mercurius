<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Memory;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\MemoryStore;

use function Mercurius\I18n\t;

/**
 * The agent marks a fact it used: useful raises its trust a little, not useful lowers it twice as much; a fact under
 * the threshold is no longer recalled. Only facts the conversation may see, once per fact in a run.
 *
 * spec: brief/memory.md#память-слои — trust of a fact follows its use
 * ref: references/hermes-plugin-holographic/store.py@4a794f8:L694-L709 (record_feedback) — +0.05 and a count, −0.10,
 *      clamped to [0, 1], the time of the change stays. Here no «confirmed»: what a person confirms goes through the
 *      owner's card (memory_propose)
 */
final class MemoryFeedbackTool implements Tool {
    public const NAME = 'memory_feedback';
    public const HELPFUL = 0.05;
    public const UNHELPFUL = -0.10;

    /** @var array<string, true> run:fact already marked */
    private array $marked = [];

    public function __construct(
        private readonly MemoryStore $store,
        private readonly ConversationAudience $audience,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.memory_feedback.about'), [
            'type' => 'object',
            'properties' => [
                'fact' => ['type' => 'integer', 'minimum' => 1],
                'useful' => ['type' => 'boolean'],
            ],
            'required' => ['fact', 'useful'],
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
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = isset($args['fact']) && is_int($args['fact']) ? $args['fact'] : 0;
        $useful = ($args['useful'] ?? null) === true;
        $fact = $id > 0 ? $this->store->fact($id) : null;
        if ($fact === null || !$fact->active || !in_array($fact->scope, $this->audience->scopes($context->principal, $context->conversationId), true)) {
            return ToolResult::error(sprintf(t('tool.memory_feedback.not_found'), $id));
        }
        $key = $context->runId . ':' . $id;
        if (isset($this->marked[$key])) {
            return ToolResult::error(sprintf(t('tool.memory_feedback.once'), $id));
        }
        $this->marked[$key] = true;
        if (count($this->marked) > 10_000) {
            $this->marked = array_slice($this->marked, -5_000, null, true);
        }
        $trust = $this->store->rateFact($id, $useful ? self::HELPFUL : self::UNHELPFUL, $useful);
        if ($trust === null) {
            return ToolResult::error(sprintf(t('tool.memory_feedback.not_found'), $id));
        }
        // spec: brief/memory.md#память-слои — the recall journal keeps whether the answer was helped; a fact found by
        //       memory_search was not recalled into the run and has no line to mark
        $this->store->markHelpful($context->runId, $id, $useful);
        return ToolResult::ok(sprintf(t($trust < Recall::MIN_TRUST - 1e-9 ? 'tool.memory_feedback.sunk' : 'tool.memory_feedback.done'), $id, $trust));
    }
}
