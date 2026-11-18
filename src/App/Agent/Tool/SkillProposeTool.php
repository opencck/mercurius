<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\SkillCards;
use OpenCCK\App\Skill\SkillCatalog;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * skill_propose: a new skill or a new version of one, from the agent; the owner accepts it with a card. Active skills
 * are never changed in place.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a lock on changes by the agent, a log of versions with rollback
 */
final class SkillProposeTool implements Tool {
    public const NAME = 'skill_propose';

    /** files of a skill the agent writes: fewer and smaller than an imported one may carry */
    public const FILES_MAX = 10;
    public const FILE_CHARS = 50_000;

    public function __construct(
        private readonly SkillCatalog $catalog,
        private readonly SkillCards $cards,
        /** where the files of a version are kept; null — a skill is its text only */
        private readonly ?\OpenCCK\Domain\Repository\SkillRepository $skills = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.skill_propose.predlagaet_vladeltsu_novyy_skill_ili')
            . t('tool.skill_propose.popadet_posle_odobreniya_skill_instrukts')
            . t('tool.skill_propose.kak_proverit_description_odna_stroka'), [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'files' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => self::FILE_CHARS], 'description' => t('tool.skill_propose.files')],
            ],
            'required' => ['name', 'description', 'body', 'reason'],
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
        // spec: BRIEF.md#скиллы-и-mcp — progressive disclosure: the text of the skill, details in its files on demand
        $files = [];
        $given = isset($args['files']) && is_array($args['files']) ? $args['files'] : [];
        if (count($given) > self::FILES_MAX || ($given !== [] && $this->skills === null)) {
            return ToolResult::error(sprintf(t('tool.skill_propose.files_bad'), self::FILES_MAX));
        }
        foreach ($given as $path => $text) {
            if (!is_string($path) || !is_string($text) || mb_strlen($text) > self::FILE_CHARS
                || \OpenCCK\App\Agent\Tool\SecretMasker::mask($text) !== $text
                || preg_match('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{E0000}-\x{E007F}]~u', $text) === 1) {
                return ToolResult::error(sprintf(t('tool.skill_propose.files_bad'), self::FILES_MAX));
            }
            $files[$path] = $text;
        }
        if ($files !== []) {
            try {
                // the same rules of paths as for a skill that comes from outside
                $files = \OpenCCK\App\Skill\SkillBundle::fromFiles(['SKILL.md' => "---\nname: x\n---\nx"] + $files, 'agent', '')->files;
            } catch (InvalidArgumentException $e) {
                return ToolResult::error($e->getMessage());
            }
        }
        try {
            $version = $this->catalog->propose(
                Args::string($args, 'name') ?? '',
                Args::string($args, 'description') ?? '',
                Args::string($args, 'body') ?? '',
                Args::string($args, 'reason') ?? '',
                $context->principal->id,
                'agent',
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        $store = $this->skills;
        if ($files !== [] && $store !== null) {
            $store->attachFiles($version->id, $files);
        }
        $extra = $files === [] ? '' : t('tool.skill_propose.card_files') . "\n" . implode("\n", array_map(static fn(string $p, string $t): string => sprintf('- %s (%d)', $p, mb_strlen($t)), array_keys($files), $files));
        if (!$this->cards->show($version, $extra)) {
            return ToolResult::error(sprintf(t('tool.skill_propose.versiya_skilla_sohranena_no_kartochku'), $version->version, $version->name));
        }
        return ToolResult::ok(sprintf(t('tool.skill_propose.skill_versii_otpravlen_vladeltsu_na'), $version->name, $version->version));
    }
}
