<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\Proposal;

use function Mercurius\I18n\t;

/**
 * Proposes a change to memory; the owner accepts or rejects it with a card. The run does not wait for the decision.
 *
 * spec: BRIEF.md#фаза-6-по-частям — the agent proposes, the owner decides
 */
final class MemoryProposeTool implements Tool {
    public const NAME = 'memory_propose';
    /** Cards one run may send, so a confused run does not flood the owner. */
    public const PER_RUN = 5;

    /** @var array<int, int> run => proposals sent */
    private array $sent = [];

    public function __construct(
        private readonly Proposals $proposals,
        private readonly MemoryCards $cards,
        private readonly ConversationAudience $audience,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.memory_propose.predlagaet_vladeltsu_izmenit_dolgovremen')
            . t('tool.memory_propose.primet_kartochku_add_novyy_fakt')
            . t('tool.memory_propose.razgovora_ne_sekrety_change_novaya')
            . t('tool.memory_propose.nasovsem_profile_i_summary_polnyy')
            . t('tool.memory_propose.vladeltsa_reason_pochemu_korotko_vladele'), [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => Proposal::KINDS],
                'fact_id' => ['type' => 'integer', 'minimum' => 1],
                'text' => ['type' => 'string', 'maxLength' => Proposals::SUMMARY_MAX],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'about_person' => ['type' => 'boolean', 'description' => t('tool.memory_propose.about_person')],
            ],
            'required' => ['kind', 'reason'],
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
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (($this->sent[$context->runId] ?? 0) >= self::PER_RUN) {
            return ToolResult::error(sprintf(t('tool.memory_propose.v_odnom_zapuske_ne_bolshe'), self::PER_RUN));
        }
        try {
            $proposal = $this->proposals->propose(
                Args::string($args, 'kind') ?? '',
                Args::int($args, 'fact_id'),
                Args::string($args, 'text') ?? '',
                Args::string($args, 'reason') ?? '',
                $this->audience->scopes($context->principal, $context->conversationId),
                $context->principal->id,
                $context->runId,
                ($args['about_person'] ?? false) === true,
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        $this->sent[$context->runId] = ($this->sent[$context->runId] ?? 0) + 1;
        if (count($this->sent) > 100) {
            $this->sent = array_slice($this->sent, -50, null, true);
        }
        if (!$this->cards->show($proposal)) {
            return ToolResult::error(sprintf(t('tool.memory_propose.predlozhenie_sohraneno_no_kartochku_vlad'), $proposal->id));
        }
        if ($proposal->kind === \OpenCCK\Memory\Domain\Proposal::ADD && $proposal->factId !== null) {
            return ToolResult::ok(sprintf(t('tool.memory_propose.written'), $proposal->factId));
        }
        return ToolResult::ok(sprintf(t('tool.memory_propose.predlozhenie_otpravleno_vladeltsu_pamyat'), $proposal->id));
    }
}
