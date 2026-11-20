<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Skill\SkillImporter;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;

use function Mercurius\I18n\t;

/**
 * The owner's `/skills` and `/skill`: the skills of this chat — built-in, the owner's folder and the active project's —
 * with how often each was opened; `/skill <name>` shows one, `pin`/`unpin` keep it off the list of stale ones,
 * `/skill import <url> [project]` brings one into the owner's or the project's folder.
 *
 * spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — skills are files: built-in ones plus the folders of the participant and the project
 */
final class SkillCommands {
    /** @param Closure(string, string, string): ?Project $projectOf the active project of a chat and topic */
    public function __construct(
        private readonly SkillLibrary $library,
        private readonly Closure $projectOf,
        private readonly ?SkillImporter $importer = null,
        private readonly ?\OpenCCK\App\Agent\Sandbox\Checkpoints $checkpoints = null,
    ) {}

    public function list(Principal $who, IncomingMessage $message): string {
        $project = $this->project($message);
        $catalog = $this->library->catalog($who, $project);
        if ($catalog === []) {
            return t('service.skill_commands.skillov_net_poprosite_menya_predlozhit');
        }
        $stats = $this->library->stats($who, $project, $catalog);
        $lines = [];
        foreach ($catalog as $name => $skill) {
            $off = ($stats[$name]['off'] ?? false) ? ' · ' . t('service.skill_commands.off') : '';
            $lines[] = sprintf('%s [%s]%s%s: %s', $name, $skill['where'], self::usage($name, $stats), $off, $skill['description']);
        }
        return t('service.skill_commands.skilly') . implode("\n", $lines) . t('service.skill_commands.usage_files');
    }

    public function skill(Principal $who, IncomingMessage $message, string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $name = $parts[0] ?? '';
        $action = $parts[1] ?? 'show';
        $project = $this->project($message);
        if ($name === 'import' && $this->importer !== null) {
            $where = ($parts[2] ?? '') === 'project' ? 'project' : 'mine';
            $target = $this->library->target($who, $project, $where);
            if ($target === null) {
                return t('tool.skill_save.no_project');
            }
            try {
                [$bundle, $findings] = $this->importer->fetch($parts[1] ?? '', new TimeoutCancellation(60));
                $dir = $this->library->write($bundle, $target, new TimeoutCancellation(30), '/skill import', $parts[1] ?? null);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
            return sprintf(t('tool.skill_import.done'), $bundle->name, $dir, $bundle->files === [] ? '—' : implode(', ', array_keys($bundle->files)), $findings === [] ? t('skill.import.clean') : implode("\n", array_slice($findings, 0, 10)));
        }
        // spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — off hides a skill from the index, remove deletes its folder after a snapshot
        if ($action === 'off' || $action === 'on') {
            if (!$this->library->counts()) {
                return t('service.skill_commands.skill_imya_show_on_off_2');
            }
            if (!$this->library->mark($who, $project, $name, 'off', $action === 'off')) {
                return sprintf(t('service.skill_commands.skilla_net_skills_spisok'), $name);
            }
            return sprintf($action === 'off' ? t('service.skill_commands.off_now') : t('service.skill_commands.on_now'), $name);
        }
        // spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — an imported skill is taken again from its source when the revision changed
        if ($action === 'update' && $this->importer !== null) {
            $origin = $this->library->origin($who, $project, $name, new TimeoutCancellation(30));
            if ($origin === null) {
                return sprintf(t('service.skill_commands.no_source'), $name);
            }
            try {
                [$bundle, $findings] = $this->importer->fetch($origin['source'], new TimeoutCancellation(60));
                if ($bundle->revision !== '' && $bundle->revision === $origin['revision']) {
                    return sprintf(t('service.skill_commands.up_to_date'), $name, $origin['revision']);
                }
                if ($bundle->name !== $name) {
                    return sprintf(t('service.skill_commands.other_name'), $origin['source'], $bundle->name, $name);
                }
                $this->library->write($bundle, $origin['target'], new TimeoutCancellation(30), '/skill update', $origin['source']);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
            return sprintf(t('service.skill_commands.updated'), $name, $origin['revision'] === '' ? '—' : $origin['revision'], $bundle->revision, $findings === [] ? t('skill.import.clean') : implode("\n", array_slice($findings, 0, 10)));
        }
        // spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — the journal of a skill's edits and the rollback of one of them
        if ($action === 'history') {
            $edits = $this->library->history($who, $project, $name);
            if ($edits === []) {
                return sprintf(t('service.skill_commands.history_none'), $name);
            }
            return sprintf(t('service.skill_commands.history_head'), $name) . implode("\n", array_map(
                static fn(array $e): string => sprintf('#%d · %s · %s%s', $e['id'], gmdate('Y-m-d H:i', $e['at']) . ' UTC', $e['source'] === '' ? '—' : $e['source'], $e['created'] ? ' · ' . t('service.skill_commands.created') : ''),
                $edits,
            ));
        }
        if ($action === 'rollback') {
            $id = (int) ltrim($parts[2] ?? '', '#');
            $why = $this->library->rollback($who, $project, $name, $id, new TimeoutCancellation(60));
            return $why ?? sprintf(t('service.skill_commands.rolled_back'), $name, $id);
        }
        if ($action === 'remove') {
            $checkpoints = $this->checkpoints;
            $why = $this->library->remove($who, $project, $name, static function (int $owner, \OpenCCK\Domain\Agent\SandboxLimits $limits) use ($checkpoints, $name): void {
                $checkpoints?->take($owner, $limits, 'before removing skill ' . $name, new TimeoutCancellation(60));
            }, new TimeoutCancellation(60));
            return $why ?? sprintf(t('service.skill_commands.removed_now'), $name);
        }
        if ($action === 'pin' || $action === 'unpin') {
            if (!$this->library->counts()) {
                return t('service.skill_commands.skill_imya_show_on_off_2');
            }
            if (!$this->library->mark($who, $project, $name, 'pin', $action === 'pin')) {
                return sprintf(t('service.skill_commands.skilla_net_skills_spisok'), $name);
            }
            return sprintf($action === 'pin' ? t('service.skill_commands.pinned_now') : t('service.skill_commands.unpinned_now'), $name);
        }
        $skill = $this->library->view($who, $project, $name, null, new TimeoutCancellation(30));
        if ($skill === null) {
            return sprintf(t('service.skill_commands.skilla_net_skills_spisok'), $name);
        }
        return $name . "\n\n" . $skill['text'] . ($skill['files'] === [] ? '' : "\n\n" . implode("\n", array_map(static fn(string $f): string => '- ' . $f, $skill['files'])));
    }

    private function project(IncomingMessage $message): ?Project {
        return ($this->projectOf)($message->channel, $message->chatId, MessageMeta::topicKey($message));
    }

    /**
     * How a skill is used: openings, stale or pinned.
     *
     * ref: references/hermes-agent/tools/skill_usage.py@e96221fa:L1-L6 — active → stale → archived, pinned opts out;
     *      here the list only marks the candidates, removing a skill is removing its folder
     * @param array<string, array{views: int, last: ?int, created: int, pinned: bool, off: bool}> $stats
     */
    private static function usage(string $name, array $stats): string {
        $s = $stats[$name] ?? null;
        if ($s === null) {
            return '';
        }
        if ($s['pinned']) {
            return ' · ' . t('service.skill_commands.pinned') . ($s['views'] > 0 ? ', ' . sprintf(t('service.skill_commands.views'), $s['views']) : '');
        }
        if (SkillLibrary::stale($s)) {
            return ' · ' . t('service.skill_commands.stale');
        }
        return $s['views'] > 0 ? ' · ' . sprintf(t('service.skill_commands.views'), $s['views']) : '';
    }
}
