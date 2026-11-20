<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;

use function Mercurius\I18n\t;

/**
 * Changes which model plays a role. A write without approval; the reason is required and stays in the table.
 * Who may call it is the participant's tool policy: the owner always, others when the owner allowed it.
 *
 * spec: brief/agent-loop.md#модели — set_model_role, who manages roles
 */
final class SetModelRoleTool implements Tool, GrantKeyed {
    public const NAME = 'set_model_role';

    public function __construct(private readonly ModelRoles $roles) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.set_model_role.naznachaet_model_roli_cheap_vspomogateln')
            . t('tool.set_model_role.strong_slozhnye_media_izobrazheniya_i')
            . t('tool.set_model_role.ona_dolzhna_byt_v_kataloge')
            . t('tool.set_model_role.novaya_model_deystvuet_dlya_dialogov'), [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string', 'enum' => ModelRole::ALL],
                'model' => ['type' => 'string', 'minLength' => 3],
                'reason' => ['type' => 'string', 'minLength' => 1],
                'fix' => ['type' => 'boolean'],
            ],
            'required' => ['role', 'model', 'reason'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * A cheaper or equal model is a write; fixing a role or a pricier (or unpriced) model goes to the owner first —
     * a page the agent read must not be able to switch every conversation to an expensive model.
     */
    public function danger(array $args): DangerClass {
        if (($args['fix'] ?? false) === true || $this->roles->pricier(Args::string($args, 'role') ?? '', Args::string($args, 'model') ?? '')) {
            return DangerClass::Irreversible;
        }
        return DangerClass::Write;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target
     */
    public function grantKey(array $args): ?string {
        $role = Args::string($args, 'role') ?? '';
        $model = Args::string($args, 'model') ?? '';
        return $role === '' || $model === '' ? null : sprintf('model-role:%s:%s', $role, $model);
    }

    public function describe(array $args): string {
        return sprintf('set_model_role %s → %s', Args::string($args, 'role') ?? '', Args::string($args, 'model') ?? '');
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        try {
            $change = $this->roles->set(
                Args::string($args, 'role') ?? '',
                Args::string($args, 'model') ?? '',
                Args::string($args, 'reason') ?? '',
                $context->principal,
                ($args['fix'] ?? false) === true,
            );
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        $lines = [];
        foreach ($this->roles->all() as $role) {
            $lines[] = sprintf('%s: %s (%s; %s)', $role->role, $role->model, $role->setBy, $role->reason);
        }
        return ToolResult::ok(t('tool.set_model_role.roli_modeley') . implode("\n", $lines) . "\n" . sprintf(t('tool.set_model_role.moved'), $change->moved));
    }
}
