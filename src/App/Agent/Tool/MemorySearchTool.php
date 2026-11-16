<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Memory\App\Recall;

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
        return new ToolDefinition(self::NAME, 'Ищет в долговременной памяти факты по запросу: о владельце, его серверах, проектах, '
            . 'договорённостях. Возвращает строки «[#номер] факт»; номер нужен, чтобы предложить изменить, завершить или забыть факт.', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 500],
            ],
            'required' => ['query'],
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
        $facts = $this->recall->find(Args::string($args, 'query') ?? '', $this->audience->scopes($context->principal, $context->conversationId));
        return ToolResult::ok($facts === [] ? 'Ничего не нашлось.' : Recall::format($facts));
    }
}
