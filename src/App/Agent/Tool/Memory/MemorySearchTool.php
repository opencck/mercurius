<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Memory;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use Mercurius\Memory\App\Recall;

use function Mercurius\I18n\t;

/**
 * Searches memory within the conversation's audience: the same facts the run could be given, on the model's request.
 *
 * spec: brief/memory.md#как-сделано-области-и-факты
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
                // spec: brief/memory.md#факты — the weekly review shows facts that fell under the threshold
                'faded' => ['type' => 'boolean', 'description' => t('tool.memory_search.faded')],
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
        if (($args['faded'] ?? false) === true) {
            if (!$context->principal->isOwner()) {
                return ToolResult::error(t('tool.memory_search.faded'));
            }
            $faded = $this->recall->faded($scopes);
            return ToolResult::ok($faded === [] ? t('tool.memory_search.faded_none') : implode("\n", array_map(static fn(\Mercurius\Memory\Domain\Fact $f): string => sprintf('#%d %s (%.2f)', $f->id, $f->text, $f->trust), $faded)));
        }
        if ($related === null && Args::string($args, 'query') === null) {
            return ToolResult::error(t('tool.memory_search.nuzhen_query_ili_related'));
        }
        $facts = $related !== null ? $this->recall->related($related, $scopes) : $this->recall->find(Args::string($args, 'query') ?? '', $scopes);
        return ToolResult::ok($facts === [] ? t('tool.memory_search.nichego_ne_nashlos') : Recall::format($facts));
    }
}
