<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * Who is in the system: people and agents with their number, role, status and current rights. The owner asks "what
 * can I change for Sergey" — the agent finds him here, then changes his rights by the number.
 *
 * spec: brief/participants.md#участники-люди-и-агенты — the owner manages participants in the dialog
 */
final class ParticipantsTool implements Tool {
    /**
     * The roles of the owner's list by name, «talk» for the visitor and «rejected».
     *
     * spec: brief/participants.md#свои-роли
     *
     * @return array<string, array{Role, PrincipalStatus}> role argument => [role, status]
     */
    private static function decisions(): array {
        $decisions = ['talk' => [Role::visitor(), PrincipalStatus::Active]];
        foreach (Role::assignable() as $role) {
            $decisions[$role->value] = [$role, PrincipalStatus::Active];
        }
        $decisions['rejected'] = [Role::visitor(), PrincipalStatus::Rejected];
        return $decisions;
    }

    /** The owner, or a participant whose role has the power over participants. */
    private static function manages(Principal $who): bool {
        return $who->isOwner() || $who->role->may(Power::Participants);
    }

    public const NAME = 'participants';

    /** @param (\Closure(): list<array{name: string, fingerprint: string, principal: int, revoked: bool}>)|null $keys the keys of agents */
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
        private readonly ?\Closure $keys = null,
        /** @var (\Closure(int): bool)|null whether a conversation is the owner's private chat; null — not checked */
        private readonly ?\Closure $privateChat = null,
    ) {}

    /**
     * The key an agent connects with: two agents may share a name, never a key. An agent without a key cannot connect.
     *
     * @return array<int, string> principal => «SHA256:… (revoked)»
     */
    private function keysByAgent(): array {
        $found = [];
        foreach ($this->keys === null ? [] : ($this->keys)() as $key) {
            $found[$key['principal']] = $key['fingerprint'] . ($key['revoked'] ? t('tool.participants.key_revoked') : '');
        }
        return $found;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.participants.uchastniki_lyudi_i_agenty_kotorye')
            . t('tool.participants.instrumenty_i_limity_query_chast') . t('tool.participants.decide_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'decide']],
                'query' => ['type' => 'string', 'maxLength' => 100],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'role' => ['type' => 'string', 'enum' => array_keys(self::decisions())],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        // spec: brief/participants.md#выдача-прав-и-владение — granting rights is always a card, in the owner's private chat
        return Args::string($args, 'action') === 'decide' ? DangerClass::Irreversible : DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!self::manages($context->principal)) {
            return ToolResult::error(t('tool.participants.spisok_uchastnikov_vidit_tolko_vladelets'));
        }
        $query = mb_strtolower(trim(Args::string($args, 'query') ?? ''));
        if (Args::string($args, 'action') === 'decide') {
            if ($this->privateChat !== null && !($this->privateChat)($context->conversationId)) {
                return ToolResult::error(t('tool.participants.private_chat_only'));
            }
            return $this->decide($context->principal, Args::int($args, 'id'), $query, Args::string($args, 'role') ?? '');
        }
        $keys = $this->keysByAgent();
        $found = array_values(array_filter($this->principals->all(), static fn(Principal $p): bool => $query === ''
            || (string) $p->id === $query
            || str_contains(mb_strtolower($p->displayName ?? ''), $query)
            || str_contains(mb_strtolower($keys[$p->id] ?? ''), $query)));
        if ($found === []) {
            return ToolResult::ok($query === '' ? t('tool.participants.uchastnikov_net') : sprintf(t('tool.participants.uchastnika_net'), $query));
        }
        return ToolResult::ok(implode("\n", array_map(fn(Principal $p): string => $this->line($p, $keys), $found)));
    }

    /**
     * What a participant may do, decided by the owner in the dialog — also without the card: someone seen in a group
     * gets rights before they write to the bot, and a lost card does not leave them waiting.
     *
     * spec: brief/participants.md#участники-люди-и-агенты — roles are the owner's decision
     */
    private function decide(Principal $who, ?int $id, string $query, string $role): ToolResult {
        $decision = self::decisions()[$role] ?? null;
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
        // spec: brief/participants.md#свои-роли — a holder of the power changes others, not themselves, and
        //       gives no role with a power they do not have
        if (!$who->isOwner() && ($found[0]->id === $who->id || array_diff(self::names($decision[0]->powers()), self::names($who->role->powers())) !== [])) {
            return ToolResult::error(t('tool.participants.above_own'));
        }
        $decided = $this->principals->setRole($found[0]->id, $decision[0], $decision[1]);
        if ($decided === null) {
            return ToolResult::error(t('tool.participants.owner_stays'));
        }
        // spec: brief/participants.md#права-проекта — a new role may take rights the participant's projects use
        $this->policies->changed();
        return ToolResult::ok(sprintf(t('tool.participants.decided'), $decided->id, $decided->displayName ?? '', $decided->role->value, $decided->status->value));
    }

    /**
     * @param list<Power> $powers
     * @return list<string>
     */
    private static function names(array $powers): array {
        return array_map(static fn(Power $p): string => $p->value, $powers);
    }

    /** @param array<int, string> $keys */
    private function line(Principal $p, array $keys = []): string {
        $head = sprintf(
            t('tool.participants.rol'),
            $p->id,
            $p->displayName ?? t('tool.participants.bez_imeni'),
            $p->kind === PrincipalKind::Agent ? t('tool.participants.agent') : t('tool.participants.chelovek'),
            $p->role->value,
            $p->status->value,
        );
        if ($p->kind === PrincipalKind::Agent && $this->keys !== null) {
            $head .= isset($keys[$p->id]) ? sprintf(t('tool.participants.key'), $keys[$p->id]) : t('tool.participants.no_key');
        }
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
