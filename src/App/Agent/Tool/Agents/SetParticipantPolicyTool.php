<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use DomainException;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\ChecksBeforeApproval;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * Changes another participant's tools and limits. Works only in the owner's runs: the owner asks in words,
 * the agent calls the tool. Values above the owner's are cut down to them.
 *
 * spec: BRIEF.md#права-участника — who changes policies
 */
final class SetParticipantPolicyTool implements Tool, ChecksBeforeApproval {
    public const NAME = 'set_participant_policy';

    /**
     * @param (\Closure(int): bool)|null $privateChat whether a conversation is the owner's private chat; null — not checked
     */
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
        private readonly ?\Closure $privateChat = null,
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
                'daily_spend_usd' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10000, 'description' => t('tool.set_participant_policy.daily_spend')],
            ],
            'required' => ['principal_id'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — granting rights is always a card and only in the owner's private
     *       chat: it is what turns one steered call into lasting access
     */
    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    /**
     * The owner changes anyone's settings; a participant whose role has the power over participants changes others',
     * never the owner's or their own, and gives no tool they lack.
     *
     * spec: BRIEF.md#свои-роли-решение-владельца-2026-11-19
     *
     * @param array<string, mixed> $args
     */
    private function forbidden(array $args, ToolContext $context): ?string {
        $who = $context->principal;
        if ($who->isOwner()) {
            return null;
        }
        if (!$who->role->may(Power::Participants)) {
            return t('tool.set_participant_policy.prava_uchastnikov_menyaet_tolko_vladelet');
        }
        $target = Args::int($args, 'principal_id');
        $granted = Args::strings($args, 'tools');
        $foreign = array_filter($granted, static fn(string $tool): bool => !$context->policy?->allowsTool($tool));
        return $target === $who->id || $this->principals->find($target ?? 0)?->isOwner() === true || $foreign !== []
            ? t('tool.participants.above_own')
            : null;
    }

    public function refuseEarly(array $args, ToolContext $context): ?ToolResult {
        $forbidden = $this->forbidden($args, $context);
        if ($forbidden !== null) {
            return ToolResult::error($forbidden);
        }
        if ($this->privateChat !== null && !($this->privateChat)($context->conversationId)) {
            return ToolResult::error(t('tool.participants.private_chat_only'));
        }
        return null;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $forbidden = $this->forbidden($args, $context);
        if ($forbidden !== null) {
            return ToolResult::error($forbidden);
        }
        if ($this->privateChat !== null && !($this->privateChat)($context->conversationId)) {
            return ToolResult::error(t('tool.participants.private_chat_only'));
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
