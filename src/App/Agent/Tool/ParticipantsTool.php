<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Repository\PrincipalRepository;

/**
 * Who is in the system: people and agents with their number, role, status and current rights. The owner asks "what
 * can I change for Sergey" — the agent finds him here, then changes his rights by the number.
 *
 * spec: BRIEF.md#участники-люди-и-агенты — the owner manages participants in the dialog
 */
final class ParticipantsTool implements Tool {
    public const NAME = 'participants';

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Участники: люди и агенты, которые пишут агенту, — номер (principal_id), имя, вид, роль, статус и текущие права '
            . '(инструменты и лимиты). query — часть имени или номер; без него — все. Только для владельца.', [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'maxLength' => 100]],
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
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error('Список участников видит только владелец.');
        }
        $query = mb_strtolower(trim(Args::string($args, 'query') ?? ''));
        $found = array_values(array_filter($this->principals->all(), static fn(Principal $p): bool => $query === ''
            || (string) $p->id === $query
            || str_contains(mb_strtolower($p->displayName ?? ''), $query)));
        if ($found === []) {
            return ToolResult::ok($query === '' ? 'Участников нет.' : sprintf('Участника «%s» нет.', $query));
        }
        return ToolResult::ok(implode("\n", array_map(fn(Principal $p): string => $this->line($p), $found)));
    }

    private function line(Principal $p): string {
        $head = sprintf(
            '#%d %s — %s, роль %s, %s',
            $p->id,
            $p->displayName ?? 'без имени',
            $p->kind === PrincipalKind::Agent ? 'агент' : 'человек',
            $p->role->value,
            $p->status->value,
        );
        if ($p->isOwner()) {
            return $head . '; владелец — все права';
        }
        $policy = $this->policies->forPrincipal($p);
        $tools = $policy->tools === null ? 'все' : ($policy->tools === [] ? 'нет' : implode(', ', $policy->tools));
        return sprintf(
            '%s; инструменты: %s; лимиты: %d вызовов модели, %d токенов, %d с на запуск%s',
            $head,
            $tools,
            $policy->run->maxTurns,
            $policy->run->tokenBudget,
            $policy->run->runTimeout,
            $policy->mayRequestApproval ? '; может просить подтверждения' : '',
        );
    }
}
