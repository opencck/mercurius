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
    /** a skill not opened for this many days is a candidate for the archive, unless pinned */
    public const STALE_DAYS = 30;

    public function __construct(
        private readonly SkillRepository $skills,
        private readonly ?\OpenCCK\App\Skill\SkillImporter $importer = null,
        private readonly ?\OpenCCK\Domain\Repository\SkillUsageRepository $usage = null,
    ) {}

    /**
     * How a skill is used: openings, stale or pinned.
     *
     * spec: BRIEF.md#самоулучшение-решено-2026-11-18
     * ref: references/hermes-agent/tools/skill_usage.py@e96221fa:L1-L6 — active → stale → archived, pinned opts out;
     *      here the owner archives (turns off) by hand, the list only marks the candidates
     * @param array<string, array{views: int, last: ?int, pinned: bool}> $stats
     */
    private static function usage(string $name, array $stats): string {
        $s = $stats[$name] ?? null;
        if ($s === null) {
            return '';
        }
        if ($s['pinned']) {
            return ' · ' . t('service.skill_commands.pinned') . ($s['views'] > 0 ? ', ' . sprintf(t('service.skill_commands.views'), $s['views']) : '');
        }
        if ($s['last'] !== null && $s['last'] < time() - self::STALE_DAYS * 86400) {
            return ' · ' . t('service.skill_commands.stale');
        }
        return $s['views'] > 0 ? ' · ' . sprintf(t('service.skill_commands.views'), $s['views']) : '';
    }

    public function list(): string {
        $all = $this->skills->all();
        if ($all === []) {
            return t('service.skill_commands.skillov_net_poprosite_menya_predlozhit');
        }
        $stats = $this->usage?->stats() ?? [];
        return t('service.skill_commands.skilly') . implode("\n", array_map(static fn(Skill $s): string => sprintf(
            '%s v%d — %s%s%s',
            $s->name,
            $s->version,
            match ($s->status) {
                Skill::ACTIVE => t('service.skill_commands.vklyuchen'),
                Skill::DISABLED => t('service.skill_commands.vyklyuchen'),
                default => t('service.skill_commands.zhdet_odobreniya'),
            },
            self::usage($s->name, $stats),
            $s->description === '' ? '' : ': ' . $s->description,
        ), $all)) . t('service.skill_commands.skill_imya_show_on_off');
    }

    public function skill(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $name = $parts[0] ?? '';
        $action = $parts[1] ?? 'show';
        if ($name === 'import' && $this->importer !== null) {
            // spec: BRIEF.md#скиллы-и-mcp — /skill import <url> [reason]: an ordinary SKILL.md of another agent
            try {
                $version = $this->importer->import($parts[1] ?? '', implode(' ', array_slice($parts, 2)), null, new \Amp\TimeoutCancellation(60));
                return sprintf(t('skill.import.proposed'), $version->name, $version->version);
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }
        }
        $skill = $this->skills->find($name);
        if ($skill === null) {
            return sprintf(t('service.skill_commands.skilla_net_skills_spisok'), $name);
        }
        return match ($action) {
            'show' => sprintf("%s v%d (%s)\n%s\n\n%s", $skill->name, $skill->version, $skill->status, $skill->description, $skill->body === '' ? t('service.skill_commands.esche_ne_odobren') : $skill->body),
            'on' => $this->skills->setStatus($name, Skill::ACTIVE) ? sprintf(t('service.skill_commands.skill_vklyuchen'), $name) : t('service.skill_commands.snachala_odobrite_versiyu_skilla'),
            'off' => $this->skills->setStatus($name, Skill::DISABLED) ? sprintf(t('service.skill_commands.skill_vyklyuchen'), $name) : t('service.skill_commands.nechego_vyklyuchat'),
            'pin' => $this->pin($name, true),
            'unpin' => $this->pin($name, false),
            'history' => t('service.skill_commands.prinyatye_versii') . implode("\n", array_map(static fn(SkillVersion $v): string => sprintf('v%d — %s', $v->version, $v->description), $this->skills->history($name))),
            'rollback' => ($rolled = $this->skills->rollback($name, (int) ($parts[2] ?? 0))) === null
                ? t('service.skill_commands.takoy_prinyatoy_versii_net_skill') . $name . ' history'
                : sprintf(t('service.skill_commands.skill_otkatan_k_versii'), $name, $rolled->version),
            default => t('service.skill_commands.skill_imya_show_on_off_2'),
        };
    }

    private function pin(string $name, bool $pinned): string {
        if ($this->usage === null) {
            return t('service.skill_commands.skill_imya_show_on_off_2');
        }
        $this->usage->pin($name, $pinned);
        return sprintf($pinned ? t('service.skill_commands.pinned_now') : t('service.skill_commands.unpinned_now'), $name);
    }
}
