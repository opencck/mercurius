<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\RequestsAccess;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The participant the agent works for (a person or a neighbouring agent) lacks a tool the task needs: the agent asks the
 * owner for it with a card that names the tool and why. The owner's "yes" gives the tool for good (it is added to the
 * participant's policy) and the run goes on with it; "no" is the answer.
 *
 * spec: brief/network.md#участник-просит-инструмент
 * ref: references/hermes-agent/tools/approval.py@e96221fa:L64-L111 — hermes asks the owner per action; here the owner
 *      is asked for the right itself, once, and the policy keeps it
 */
final class RequestToolTool implements Tool, GrantKeyed, RequestsAccess {
    public const NAME = 'request_tool';

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly PolicyService $policies,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.request_tool.about'), [
            'type' => 'object',
            'properties' => [
                'tool' => ['type' => 'string', 'enum' => $this->others(), 'description' => t('tool.request_tool.tool')],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => t('tool.request_tool.reason')],
            ],
            'required' => ['tool', 'reason'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        // a right is the owner's decision every time, never a saved grant
        return null;
    }

    public function describe(array $args): string {
        return sprintf(t('tool.request_tool.describe'), Args::string($args, 'tool') ?? '', Args::string($args, 'reason') ?? '');
    }

    public function grantedTool(array $args): string {
        return Args::string($args, 'tool') ?? '';
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $name = Args::string($args, 'tool') ?? '';
        if (!in_array($name, $this->others(), true)) {
            return ToolResult::error(sprintf(t('tool.request_tool.unknown'), $name));
        }
        $current = $this->policies->forPrincipal($context->principal);
        if ($current->allowsTool($name)) {
            return ToolResult::ok(sprintf(t('tool.request_tool.already'), $name));
        }
        $this->policies->update($context->principal, ['tools' => array_values(array_unique([...($current->tools ?? []), $name]))]);
        return ToolResult::ok(sprintf(t('tool.request_tool.granted'), $name));
    }

    /** @return list<string> every registered tool but this one */
    private function others(): array {
        return array_values(array_filter($this->registry->all(), static fn(string $n): bool => $n !== self::NAME));
    }
}
