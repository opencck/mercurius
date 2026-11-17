<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Skill\SkillCatalog;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * skill_view: the body of an active skill. Skills are the owner's accepted instructions, so the body is not marked as
 * outside data.
 *
 * spec: BRIEF.md#скиллы-и-mcp — the body is loaded on demand
 */
final class SkillViewTool implements Tool {
    public const NAME = 'skill_view';

    public function __construct(private readonly SkillCatalog $catalog) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_view.zagruzhaet_tekst_skilla_iz_kataloga'), [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
            'required' => ['name'],
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
        $name = Args::string($args, 'name') ?? '';
        $body = $this->catalog->body($name);
        return $body === null ? ToolResult::error(sprintf(t('tool.skill_view.aktivnogo_skilla_net'), $name)) : ToolResult::ok($body);
    }
}
