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

    public function __construct(
        private readonly SkillCatalog $catalog,
        private readonly SkillCards $cards,
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
        if (!$this->cards->show($version)) {
            return ToolResult::error(sprintf(t('tool.skill_propose.versiya_skilla_sohranena_no_kartochku'), $version->version, $version->name));
        }
        return ToolResult::ok(sprintf(t('tool.skill_propose.skill_versii_otpravlen_vladeltsu_na'), $version->name, $version->version));
    }
}
