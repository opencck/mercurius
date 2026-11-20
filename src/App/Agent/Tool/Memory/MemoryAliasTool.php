<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Memory;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Memory\App\Aliases;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent adds words that mean one thing to the owner's table («прокс» — «proxmox»), or reads it: search then finds a
 * fact by any of them. The owner sees and edits the table with /aliases. Only in a conversation of the owner: the
 * table shapes search for everyone.
 *
 * spec: brief/memory.md#словари — the agent fills the table itself
 */
final class MemoryAliasTool implements Tool {
    public const NAME = 'memory_alias';

    public function __construct(private readonly Aliases $aliases) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.memory_alias.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'list']],
                'words' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 40],
                    'minItems' => 2,
                    'maxItems' => Aliases::MAX_WORDS,
                ],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return ($args['action'] ?? null) === 'list' ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (($args['action'] ?? null) === 'list') {
            return ToolResult::ok($this->aliases->list());
        }
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.memory_alias.owner_only'));
        }
        $words = Args::strings($args, 'words');
        if (count($words) < 2) {
            return ToolResult::error(t('app.aliases.too_few'));
        }
        [$changed, $said] = $this->aliases->add($words, $context->principal->id);
        return $changed ? ToolResult::ok($said) : ToolResult::error($said);
    }
}
