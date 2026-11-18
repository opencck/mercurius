<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\SkillUsageRepository;

use function Mercurius\I18n\t;

/**
 * Loads a skill of this conversation by name: built-in, the participant's or the active project's (the nearest wins);
 * with file_path — one of its files.
 *
 * spec: BRIEF.md#скиллы-и-mcp — skills are loaded on demand, the index is in the prompt
 * ref: references/hermes-agent/tools/skills_tool.py@e96221fa:L634 (skill_view) — name plus file_path reads a file of
 *      the skill
 */
final class SkillViewTool implements Tool {
    public const NAME = 'skill_view';

    /** @param Closure(int, ?string, \OpenCCK\Domain\Entity\Principal): ?Project $projectOf the project of a conversation: one named, else the active one */
    public function __construct(
        private readonly SkillLibrary $library,
        private readonly Closure $projectOf,
        /** openings are counted for the review and for cleaning the index; null — not counted */
        private readonly ?SkillUsageRepository $usage = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_view.about'), [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'project' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.skill.project')],
                'file_path' => ['type' => 'string', 'maxLength' => 300, 'description' => t('tool.skill_view.file_path')],
            ],
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
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $name = Args::string($args, 'name') ?? '';
        $project = ($this->projectOf)($context->conversationId, Args::string($args, 'project'), $context->principal);
        if ($name === '') {
            // spec: BRIEF.md#скиллы-и-mcp — in a private chat about a project the agent names it and gets its skills
            $lines = [];
            foreach ($this->library->catalog($context->principal, $project, $context->cancellation) as $skill => $info) {
                $lines[] = sprintf('- %s: %s [%s]', $skill, $info['description'], $info['where']);
            }
            return ToolResult::ok($lines === [] ? t('service.skill_commands.skillov_net_poprosite_menya_predlozhit') : implode("\n", $lines));
        }
        $path = Args::string($args, 'file_path');
        $path = $path === null ? null : ltrim(trim($path), './');
        $skill = $this->library->view($context->principal, $project, $name, $path === '' ? null : $path, $context->cancellation);
        if ($skill === null) {
            return ToolResult::error($path === null ? sprintf(t('tool.skill_view.aktivnogo_skilla_net'), $name) : sprintf(t('tool.skill_view.no_file'), $name, $path));
        }
        if ($path === null) {
            // ref: references/hermes-agent/tools/skill_usage.py@e96221fa:L1-L6 — a counter bump is best effort
            try {
                $this->usage?->touch($name);
            } catch (\Throwable) {
            }
        }
        return ToolResult::ok($skill['files'] === [] ? $skill['text'] : $skill['text'] . sprintf(t('tool.skill_view.files_list'), implode("\n", array_map(static fn(string $p): string => '- ' . $p, $skill['files']))));
    }
}
