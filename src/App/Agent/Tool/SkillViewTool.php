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

    public function __construct(
        private readonly SkillCatalog $catalog,
        /** openings are counted for the review and for cleaning the index; null — not counted */
        private readonly ?\OpenCCK\Domain\Repository\SkillUsageRepository $usage = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_view.zagruzhaet_tekst_skilla_iz_kataloga') . t('tool.skill_view.file_path_about'), [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'file_path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
            ],
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
        if ($body === null) {
            return ToolResult::error(sprintf(t('tool.skill_view.aktivnogo_skilla_net'), $name));
        }
        // ref: references/hermes-agent/tools/skill_usage.py@e96221fa:L1-L6 — a counter bump is best effort and never
        //      breaks the call; here in the base instead of a sidecar file
        try {
            $this->usage?->touch($name);
        } catch (\Throwable) {
        }
        // ref: references/hermes-agent/tools/skills_tool.py@e96221fa:L634 (skill_view) — name plus file_path reads a file of the skill;
        //      here files live with the version in the base, not in a directory
        $files = $this->catalog->files($name);
        $path = Args::string($args, 'file_path');
        if ($path !== null) {
            $path = ltrim(trim($path), './');
            return isset($files[$path]) ? ToolResult::ok($files[$path]) : ToolResult::error(sprintf(t('tool.skill_view.no_file'), $name, $path));
        }
        return ToolResult::ok($files === [] ? $body : $body . sprintf(t('tool.skill_view.files_list'), implode("\n", array_map(
            static fn(string $p, string $text): string => sprintf('- %s (%d)', $p, mb_strlen($text)),
            array_keys($files),
            $files,
        ))));
    }
}
