<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;

/**
 * Changes which model plays a role. A write without approval; the reason is required and stays in the table.
 * Who may call it is the participant's tool policy: the owner always, others when the owner allowed it.
 *
 * spec: BRIEF.md#модели — set_model_role, who manages roles
 */
final class SetModelRoleTool implements Tool {
    public const NAME = 'set_model_role';

    public function __construct(private readonly ModelRoles $roles) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Назначает модель роли: cheap — вспомогательные вызовы, main — обычные задачи, '
            . 'strong — сложные, media — изображения и звук. Модель — «провайдер:идентификатор» или идентификатор OpenRouter; '
            . 'она должна быть в каталоге. Причина обязательна. fix — закрепить (только владелец): больше никто не изменит. '
            . 'Новая модель действует для диалогов, открытых после изменения. Возвращает таблицу ролей.', [
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

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        try {
            $this->roles->set(
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
        return ToolResult::ok("Роли моделей:\n" . implode("\n", $lines));
    }
}
