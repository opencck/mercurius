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
        return new ToolDefinition(self::NAME, 'Предлагает владельцу изменить долговременную память; изменение вступит, когда владелец '
            . 'примет карточку. add — новый факт: одно законченное утверждение, которое пригодится в других беседах (не пересказ '
            . 'разговора, не секреты). change — новая формулировка факта fact_id; end — факт больше не верен; forget — забыть '
            . 'насовсем. profile и summary — полный новый текст профиля владельца и сводки текущих дел (только в личном чате '
            . 'владельца). reason — почему, коротко: владелец решает по ней.', [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => Proposal::KINDS],
                'fact_id' => ['type' => 'integer', 'minimum' => 1],
                'text' => ['type' => 'string', 'maxLength' => Proposals::SUMMARY_MAX],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
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
            return ToolResult::error(sprintf('В одном запуске — не больше %d предложений: дождись решения владельца.', self::PER_RUN));
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
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        $this->sent[$context->runId] = ($this->sent[$context->runId] ?? 0) + 1;
        if (!$this->cards->show($proposal)) {
            return ToolResult::error(sprintf('Предложение №%d сохранено, но карточку владельцу отправить не удалось; он увидит его в /memory.', $proposal->id));
        }
        return ToolResult::ok(sprintf('Предложение №%d отправлено владельцу. Память изменится, когда он его примет; ждать не нужно.', $proposal->id));
    }
}
