<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\PrincipalRepository;

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
        return new ToolDefinition(self::NAME, 'Меняет права участника (не владельца): доступные инструменты и лимиты запуска. '
            . 'Только по просьбе владельца. Значения больше, чем у владельца, урезаются. Возвращает итоговую политику.', [
            'type' => 'object',
            'properties' => [
                'principal_id' => ['type' => 'integer', 'minimum' => 1],
                'tools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Разрешённые инструменты'],
                'max_turns' => ['type' => 'integer', 'minimum' => 1],
                'token_budget' => ['type' => 'integer', 'minimum' => 1],
                'run_timeout' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Секунды'],
                'tool_timeout' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Секунды'],
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
            return ToolResult::error('Права участников меняет только владелец.');
        }
        $id = Args::int($args, 'principal_id');
        $target = $id === null ? null : $this->principals->find($id);
        if ($target === null) {
            return ToolResult::error(sprintf('Участник %s не найден.', $id === null ? '?' : (string) $id));
        }
        $overrides = $args;
        unset($overrides['principal_id']);
        try {
            $policy = $this->policies->update($target, $overrides);
        } catch (DomainException) {
            return ToolResult::error('Права владельца задаются в настройках сервера и здесь не меняются.');
        } catch (InvalidArgumentException $e) {
            return ToolResult::error('Неверное значение: ' . $e->getMessage());
        }
        return ToolResult::ok(json_encode($policy->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
