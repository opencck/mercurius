<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * What the agent knows about itself, on demand: the prompt keeps one line pointing here, the details come by topic —
 * how another agent connects, the channels, the commands, the tools and modules that work now, the limits of the
 * participant, self-improvement, the version. Constant texts live in the catalog of texts (`self.*`), what changes
 * comes from code at the moment of the call.
 *
 * spec: brief/agent-loop.md#знания-агента-о-себе
 * ref: references/hermes-agent/agent/prompt_builder.py@e96221fa:L171-L182 (HERMES_AGENT_HELP_GUIDANCE) — one line in
 *      the stable prompt points to the details; hermes keeps them in the `hermes-agent` skill and its docs, here a
 *      tool, because a part of them (address, modules, limits) is known only at run time
 * ref: references/hermes-agent/agent/system_prompt.py@e96221fa:L765-L775 — the pointer is put only when its target
 *      exists
 */
final class SelfInfoTool implements Tool {
    public const NAME = 'self_info';

    /** @param array<string, Closure(ToolContext): string> $topics topic => what it says now */
    public function __construct(private readonly array $topics) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.self_info.about'), [
            'type' => 'object',
            'properties' => [
                'topic' => ['type' => 'string', 'enum' => ['index', ...array_keys($this->topics)]],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 20;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $topic = Args::string($args, 'topic') ?? 'index';
        $provider = $this->topics[$topic] ?? null;
        if ($provider === null) {
            $lines = [];
            foreach (array_keys($this->topics) as $name) {
                $lines[] = sprintf('- %s — %s', $name, t('self.topic.' . $name));
            }
            return ToolResult::ok(t('tool.self_info.index') . "\n" . implode("\n", $lines));
        }
        return ToolResult::ok($provider($context));
    }
}
