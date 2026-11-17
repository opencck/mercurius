<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Domain\Skill\SkillVersion;

use function Mercurius\I18n\t;

/**
 * The owner manages the catalog from the chat: the list, a skill's text, turning it on and off, its versions and a
 * rollback.
 *
 * spec: BRIEF.md#скиллы-и-mcp — list, enable, disable from the channel, the owner only
 */
final class SkillCommands {
    public function __construct(private readonly SkillRepository $skills) {}

    public function list(): string {
        $all = $this->skills->all();
        if ($all === []) {
            return t('service.skill_commands.skillov_net_poprosite_menya_predlozhit');
        }
        return t('service.skill_commands.skilly') . implode("\n", array_map(static fn(Skill $s): string => sprintf(
            '%s v%d — %s%s',
            $s->name,
            $s->version,
            match ($s->status) {
                Skill::ACTIVE => t('service.skill_commands.vklyuchen'),
                Skill::DISABLED => t('service.skill_commands.vyklyuchen'),
                default => t('service.skill_commands.zhdet_odobreniya'),
            },
            $s->description === '' ? '' : ': ' . $s->description,
        ), $all)) . t('service.skill_commands.skill_imya_show_on_off');
    }

    public function skill(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $name = $parts[0] ?? '';
        $action = $parts[1] ?? 'show';
        $skill = $this->skills->find($name);
        if ($skill === null) {
            return sprintf(t('service.skill_commands.skilla_net_skills_spisok'), $name);
        }
        return match ($action) {
            'show' => sprintf("%s v%d (%s)\n%s\n\n%s", $skill->name, $skill->version, $skill->status, $skill->description, $skill->body === '' ? t('service.skill_commands.esche_ne_odobren') : $skill->body),
            'on' => $this->skills->setStatus($name, Skill::ACTIVE) ? sprintf(t('service.skill_commands.skill_vklyuchen'), $name) : t('service.skill_commands.snachala_odobrite_versiyu_skilla'),
            'off' => $this->skills->setStatus($name, Skill::DISABLED) ? sprintf(t('service.skill_commands.skill_vyklyuchen'), $name) : t('service.skill_commands.nechego_vyklyuchat'),
            'history' => t('service.skill_commands.prinyatye_versii') . implode("\n", array_map(static fn(SkillVersion $v): string => sprintf('v%d — %s', $v->version, $v->description), $this->skills->history($name))),
            'rollback' => ($rolled = $this->skills->rollback($name, (int) ($parts[2] ?? 0))) === null
                ? t('service.skill_commands.takoy_prinyatoy_versii_net_skill') . $name . ' history'
                : sprintf(t('service.skill_commands.skill_otkatan_k_versii'), $name, $rolled->version),
            default => t('service.skill_commands.skill_imya_show_on_off_2'),
        };
    }
}
