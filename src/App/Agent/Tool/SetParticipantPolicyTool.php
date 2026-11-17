<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * Changes another participant's tools and limits. Works only in the owner's runs: the owner asks in words,
 * the agent calls the tool. Values above the owner's are cut down to them.
 *
 * spec: BRIEF.md#права-участника — who changes policies
 */
final class SetParticipantPolicyTool implements Tool {
    public const NAME = 'set_participant_policy';

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.set_participant_policy.menyaet_prava_uchastnika_ne_vladeltsa')
            . t('tool.set_participant_policy.tolko_po_prosbe_vladeltsa_znacheniya')
            . t('tool.set_participant_policy.nomer_uchastnika_principal_id_iz'), [
            'type' => 'object',
            'properties' => [
                'principal_id' => ['type' => 'integer', 'minimum' => 1],
                'tools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => t('tool.set_participant_policy.razreshennye_instrumenty')],
                'max_turns' => ['type' => 'integer', 'minimum' => 1],
                'token_budget' => ['type' => 'integer', 'minimum' => 1],
                'run_timeout' => ['type' => 'integer', 'minimum' => 1, 'description' => t('tool.set_participant_policy.sekundy')],
                'tool_timeout' => ['type' => 'integer', 'minimum' => 1, 'description' => t('tool.set_participant_policy.sekundy')],
                'may_request_approval' => ['type' => 'boolean'],
            ],
            'required' => ['principal_id'],
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
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.set_participant_policy.prava_uchastnikov_menyaet_tolko_vladelet'));
        }
        $id = Args::int($args, 'principal_id');
        $target = $id === null ? null : $this->principals->find($id);
        if ($target === null) {
            return ToolResult::error(sprintf(t('tool.set_participant_policy.uchastnik_ne_nayden'), $id === null ? '?' : (string) $id));
        }
        $overrides = $args;
        unset($overrides['principal_id']);
        try {
            $policy = $this->policies->update($target, $overrides);
        } catch (DomainException) {
            return ToolResult::error(t('tool.set_participant_policy.prava_vladeltsa_zadayutsya_v_nastroykah'));
        } catch (InvalidArgumentException $e) {
            return ToolResult::error(t('tool.set_participant_policy.nevernoe_znachenie') . $e->getMessage());
        }
        return ToolResult::ok(json_encode($policy->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
