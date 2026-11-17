<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Memory\App\Recall;

use function Mercurius\I18n\t;

/**
 * Searches memory within the conversation's audience: the same facts the run could be given, on the model's request.
 *
 * spec: BRIEF.md#память-слои
 */
final class MemorySearchTool implements Tool {
    public const NAME = 'memory_search';

    public function __construct(
        private readonly Recall $recall,
        private readonly ConversationAudience $audience,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.memory_search.ischet_v_dolgovremennoy_pamyati_fakty')
            . t('tool.memory_search.dogovorennostyah_vozvraschaet_stroki_nom')
            . t('tool.memory_search.query_chto_ischesh_related_vmesto'), [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 500],
                'related' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $scopes = $this->audience->scopes($context->principal, $context->conversationId);
        $related = Args::string($args, 'related');
        if ($related === null && Args::string($args, 'query') === null) {
            return ToolResult::error(t('tool.memory_search.nuzhen_query_ili_related'));
        }
        $facts = $related !== null ? $this->recall->related($related, $scopes) : $this->recall->find(Args::string($args, 'query') ?? '', $scopes);
        return ToolResult::ok($facts === [] ? t('tool.memory_search.nichego_ne_nashlos') : Recall::format($facts));
    }
}
