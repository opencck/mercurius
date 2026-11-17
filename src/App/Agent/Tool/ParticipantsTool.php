<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * Who is in the system: people and agents with their number, role, status and current rights. The owner asks "what
 * can I change for Sergey" — the agent finds him here, then changes his rights by the number.
 *
 * spec: BRIEF.md#участники-люди-и-агенты — the owner manages participants in the dialog
 */
final class ParticipantsTool implements Tool {
    /** role argument => [role, status] */
    private const DECISIONS = [
        'talk' => [Role::Visitor, PrincipalStatus::Active],
        'user' => [Role::User, PrincipalStatus::Active],
        'admin' => [Role::Admin, PrincipalStatus::Active],
        'rejected' => [Role::Visitor, PrincipalStatus::Rejected],
    ];

    public const NAME = 'participants';

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.participants.uchastniki_lyudi_i_agenty_kotorye')
            . t('tool.participants.instrumenty_i_limity_query_chast') . t('tool.participants.decide_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'decide']],
                'query' => ['type' => 'string', 'maxLength' => 100],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'role' => ['type' => 'string', 'enum' => array_keys(self::DECISIONS)],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'decide' ? DangerClass::Write : DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.participants.spisok_uchastnikov_vidit_tolko_vladelets'));
        }
        $query = mb_strtolower(trim(Args::string($args, 'query') ?? ''));
        if (Args::string($args, 'action') === 'decide') {
            return $this->decide(Args::int($args, 'id'), $query, Args::string($args, 'role') ?? '');
        }
        $found = array_values(array_filter($this->principals->all(), static fn(Principal $p): bool => $query === ''
            || (string) $p->id === $query
            || str_contains(mb_strtolower($p->displayName ?? ''), $query)));
        if ($found === []) {
            return ToolResult::ok($query === '' ? t('tool.participants.uchastnikov_net') : sprintf(t('tool.participants.uchastnika_net'), $query));
        }
        return ToolResult::ok(implode("\n", array_map(fn(Principal $p): string => $this->line($p), $found)));
    }

    /**
     * What a participant may do, decided by the owner in the dialog — also without the card: someone seen in a group
     * gets rights before they write to the bot, and a lost card does not leave them waiting.
     *
     * spec: BRIEF.md#участники-люди-и-агенты — roles are the owner's decision
     */
    private function decide(?int $id, string $query, string $role): ToolResult {
        $decision = self::DECISIONS[$role] ?? null;
        if ($decision === null) {
            return ToolResult::error(t('tool.participants.bad_role'));
        }
        $found = array_values(array_filter($this->principals->all(), static fn(Principal $p): bool => $id !== null
            ? $p->id === $id
            : $query !== '' && str_contains(mb_strtolower($p->displayName ?? ''), $query)));
        if (count($found) !== 1) {
            return ToolResult::error(t('tool.participants.not_found'));
        }
        if ($found[0]->isOwner()) {
            return ToolResult::error(t('tool.participants.owner_stays'));
        }
        $decided = $this->principals->setRole($found[0]->id, $decision[0], $decision[1]);
        if ($decided === null) {
            return ToolResult::error(t('tool.participants.owner_stays'));
        }
        return ToolResult::ok(sprintf(t('tool.participants.decided'), $decided->id, $decided->displayName ?? '', $decided->role->value, $decided->status->value));
    }

    private function line(Principal $p): string {
        $head = sprintf(
            t('tool.participants.rol'),
            $p->id,
            $p->displayName ?? t('tool.participants.bez_imeni'),
            $p->kind === PrincipalKind::Agent ? t('tool.participants.agent') : t('tool.participants.chelovek'),
            $p->role->value,
            $p->status->value,
        );
        if ($p->isOwner()) {
            return $head . t('tool.participants.vladelets_vse_prava');
        }
        $policy = $this->policies->forPrincipal($p);
        $tools = $policy->tools === null ? t('tool.participants.vse') : ($policy->tools === [] ? t('tool.participants.net') : implode(', ', $policy->tools));
        return sprintf(
            t('tool.participants.line'),
            $head,
            $tools,
            $policy->run->maxTurns,
            $policy->run->tokenBudget,
            $policy->run->runTimeout,
            $policy->mayRequestApproval ? t('tool.participants.mozhet_prosit_podtverzhdeniya') : '',
        );
    }
}
