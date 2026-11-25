<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Skills;

use Closure;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Sandbox\ChangesSandbox;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Skill\SkillBundle;
use OpenCCK\App\Skill\SkillGuard;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Skill\Skill;

use function Mercurius\I18n\t;

/**
 * Writes a skill as a directory into a folder of the sandbox: `mine` — the participant's skills (their private chat),
 * `project` — the active project's skills (its chats and forum topics), only for the project's owner. A skill with
 * the name of a built-in one is the participant's or the project's own copy and wins over it. The text and the files
 * are checked like a skill from outside; the snapshots of the working folder keep the earlier versions.
 *
 * spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — skills are files in the sandbox, built-in ones plus the participant's and the project's
 * ref: references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L1-L7 — the agent creates and edits its skills as
 *      SKILL.md directories; here in the folder of whoever the skill is for
 */
final class SkillSaveTool implements Tool, ChangesSandbox {
    public const NAME = 'skill_save';
    public const FILES_MAX = 10;
    public const FILE_CHARS = 50_000;

    /** @param Closure(int, ?string, \OpenCCK\Domain\Entity\Member): ?Project $projectOf the project of a conversation: one named, else the active one */
    public function __construct(
        private readonly SkillLibrary $library,
        private readonly Closure $projectOf,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_save.about'), [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Skill::DESCRIPTION_MAX],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Skill::BODY_MAX],
                'files' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => self::FILE_CHARS], 'description' => t('tool.skill_propose.files')],
                'where' => ['type' => 'string', 'enum' => ['mine', 'project'], 'description' => t('tool.skill_save.where')],
                'project' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.skill.project')],
            ],
            'required' => ['name', 'description', 'body'],
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
        return 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        // a project named without a place means the project: a skill does not silently land in the participant's own folder
        $where = Args::string($args, 'where') ?? (trim(Args::string($args, 'project') ?? '') !== '' ? 'project' : 'mine');
        $target = $this->library->target($context->principal, ($this->projectOf)($context->conversationId, Args::string($args, 'project'), $context->principal), $where);
        if ($target === null) {
            return ToolResult::error($where === 'project' ? t('tool.skill_save.no_project') : t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika'));
        }
        $name = strtolower(trim(Args::string($args, 'name') ?? ''));
        $description = trim(Args::string($args, 'description') ?? '');
        $body = trim(Args::string($args, 'body') ?? '');
        if (preg_match(Skill::NAME_PATTERN, $name) !== 1 || $description === '' || str_contains($description, "\n") || $body === '') {
            return ToolResult::error(t('skill.skill_catalog.imya_skilla_strochnye_latinskie_bukvy'));
        }
        $given = isset($args['files']) && is_array($args['files']) ? $args['files'] : [];
        // spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — every refusal names what to fix: the model does not guess by halves
        if (count($given) > self::FILES_MAX) {
            return ToolResult::error(sprintf(t('tool.skill_save.too_many'), count($given), self::FILES_MAX));
        }
        $raw = ['SKILL.md' => "---\nname: " . $name . "\ndescription: " . $description . "\n---\n\n" . $body];
        foreach ($given as $path => $text) {
            if (!is_string($path) || !is_string($text)) {
                return ToolResult::error(t('tool.skill_save.not_text'));
            }
            if (mb_strlen($text) > self::FILE_CHARS) {
                return ToolResult::error(sprintf(t('tool.skill_save.too_long'), $path, mb_strlen($text), self::FILE_CHARS));
            }
            $raw[$path] = $text;
        }
        try {
            $bundle = SkillBundle::fromFiles($raw, 'agent', '');
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        // what lands in a skill is read in later conversations: no secrets, no hidden text, no dangerous patterns
        foreach ([$bundle->body, ...array_values($bundle->files)] as $text) {
            if (SecretMasker::mask($text) !== $text) {
                return ToolResult::error(self::secretLine($raw) ?? sprintf(t('tool.skill_propose.files_bad'), self::FILES_MAX));
            }
        }
        $findings = SkillGuard::scan($bundle);
        if (SkillGuard::blocks($findings)) {
            return ToolResult::error(sprintf(t('skill.import.blocked'), $bundle->name, implode("\n", array_map(static fn(array $f): string => sprintf('%s %s:%d — %s', $f['severity'], $f['file'], $f['line'], $f['description']), $findings))));
        }
        $dir = $this->library->write($bundle, $target, $context->cancellation, 'skill_save');
        return ToolResult::ok(sprintf(t('tool.skill_save.done'), $bundle->name, $dir));
    }

    /**
     * The first line that looks like a secret: its file, number and text with the value already masked, so the model
     * rewrites that line (a word after «паролем:» reads as a password) instead of guessing.
     *
     * @param array<string, string> $files
     */
    private static function secretLine(array $files): ?string {
        foreach ($files as $path => $text) {
            foreach (preg_split('~\R~u', $text) ?: [] as $index => $line) {
                $masked = SecretMasker::mask($line);
                if ($masked !== $line) {
                    return sprintf(t('tool.skill_save.secret_line'), $path, $index + 1, mb_substr($masked, 0, 200));
                }
            }
        }
        return null;
    }
}
