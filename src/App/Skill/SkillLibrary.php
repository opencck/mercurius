<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use Amp\NullCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Where the agent's skills are: all are ordinary directories with a SKILL.md and files next to it, in three places —
 * - built-in: `skills/<name>/` of the code (submodules), the same everywhere, read-only;
 * - the participant's: `.mercurius/skills/<name>/` in their sandbox folder — for their private chat with the agent;
 * - the project's: `projects/<number>/.mercurius/skills/<name>/` in the sandbox folder of the project's owner — for every chat
 *   and forum topic the project is active in.
 * They add up; the same name in a nearer place wins (project → participant → built-in), like the tiers of hermes
 * (references/hermes-agent/agent/skill_utils.py@e96221fa:L400-L423, get_skill_search_roots: project > local > …). A skill moves between places as
 * a directory: a built-in one copied into a folder can be changed there; a folder's skill can become a submodule.
 *
 * spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — skills are files: built-in ones plus the folders of the participant and the project
 */
final class SkillLibrary {
    /** spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — the layout of skills */
    public const MINE = '.mercurius/skills';
    /** where the participant's skills were before 2026-11-19; SkillFolders moves them */
    public const LEGACY_MINE = 'skills';
    /** skills read from one folder; more are left out of the index */
    private const PER_PLACE = 40;

    /**
     * @param array<string, SkillBundle> $builtin name => skill
     * @param Closure(int): ?SandboxLimits $limitsOf the sandbox limits of a participant; null — they have no sandbox
     * @param Closure(): ?int $ownerId the agent's owner: the owner of projects without one
     */
    public function __construct(
        private readonly array $builtin,
        private readonly ?Sandbox $sandbox,
        private readonly Closure $limitsOf,
        private readonly Closure $ownerId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    private ?\OpenCCK\Domain\Repository\SkillUsageRepository $usage = null;
    private ?\OpenCCK\Domain\Repository\SkillEditRepository $edits = null;
    /** a file of a skill kept in the journal of edits at most this big */
    private const EDIT_FILE_BYTES = 1_000_000;

    /**
     * Where a skill of this conversation came from and where it lies: null when it is built in or has no source.
     *
     * spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — /skill update
     *
     * @return array{source: string, revision: string, target: array{owner: int, dir: string, limits: SandboxLimits}}|null
     */
    public function origin(Principal $who, ?Project $project, string $name, Cancellation $cancellation): ?array {
        $skill = $this->catalog($who, $project, $cancellation)[$name] ?? null;
        if ($skill === null || $skill['dir'] === null || $skill['owner'] === null || $this->sandbox === null) {
            return null;
        }
        $limits = ($this->limitsOf)($skill['owner']);
        if ($limits === null) {
            return null;
        }
        try {
            $raw = $this->sandbox->fetch($skill['owner'], $limits, $skill['dir'] . '/SKILL.md', SkillBundle::READ_BYTES, $cancellation);
        } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
            return null;
        }
        $origin = SkillBundle::origin($raw);
        if ($origin['source'] === '') {
            return null;
        }
        return $origin + ['target' => ['owner' => $skill['owner'], 'dir' => dirname($skill['dir']), 'limits' => $limits]];
    }

    /** spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — a journal of skill edits with a rollback of one record */
    public function useEdits(\OpenCCK\Domain\Repository\SkillEditRepository $edits): void {
        $this->edits = $edits;
    }

    /**
     * The edits of a skill of this conversation, newest first.
     *
     * @return list<array{id: int, at: int, source: string, created: bool}>
     */
    public function history(Principal $who, ?Project $project, string $name): array {
        $skill = $this->catalog($who, $project)[$name] ?? null;
        if ($skill === null || $skill['dir'] === null || $skill['owner'] === null || $this->edits === null) {
            return [];
        }
        return $this->edits->forSkill($skill['owner'], $skill['dir'], 20);
    }

    /**
     * Brings a skill's folder back to what it held before edit $id: the files of that time are written, files added
     * since go; an edit that made the skill removes it. The rollback is an edit too. Null when done, else why not.
     */
    public function rollback(Principal $who, ?Project $project, string $name, int $id, Cancellation $cancellation): ?string {
        $skill = $this->catalog($who, $project, $cancellation)[$name] ?? null;
        $edit = $this->edits?->find($id);
        if ($skill === null || $skill['dir'] === null || $skill['owner'] === null || $edit === null
            || $edit['owner'] !== $skill['owner'] || $edit['dir'] !== $skill['dir']) {
            return sprintf(t('skill.library.no_edit'), $id, $name);
        }
        $limits = ($this->limitsOf)($skill['owner']);
        if ($limits === null || $this->sandbox === null) {
            return sprintf(t('skill.library.builtin_off_only'), $name);
        }
        $now = $this->folder($skill['owner'], $limits, $skill['dir'], $cancellation);
        $this->sandbox->exec($skill['owner'], $limits, 'skill-rollback-' . $skill['owner'], "rm -rf -- '" . str_replace("'", '', $skill['dir']) . "'", '.', 30, $cancellation);
        foreach ($edit['before'] ?? [] as $path => $text) {
            $this->sandbox->write($skill['owner'], $limits, $skill['dir'] . '/' . $path, $text, $cancellation);
        }
        $this->edits?->record($skill['owner'], $skill['dir'], $name, $now, 'rollback #' . $id);
        return null;
    }

    /**
     * The text files of a skill's folder now, path => text; null — there is no such folder.
     *
     * @return array<string, string>|null
     */
    private function folder(int $owner, SandboxLimits $limits, string $dir, Cancellation $cancellation): ?array {
        if ($this->sandbox === null) {
            return null;
        }
        try {
            $entries = $this->sandbox->list($owner, $limits, $dir, 6, $cancellation);
        } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
            return null;
        }
        $files = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'file' || $entry['size'] > self::EDIT_FILE_BYTES) {
                continue;
            }
            try {
                $text = $this->sandbox->fetch($owner, $limits, $dir . '/' . $entry['path'], self::EDIT_FILE_BYTES, $cancellation);
            } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
                continue;
            }
            if (mb_check_encoding($text, 'UTF-8') && !str_contains($text, "\0")) {
                $files[$entry['path']] = $text;
            }
        }
        return $files === [] ? null : $files;
    }

    /** spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — a skill turned off is left out of the index */
    public function useUsage(\OpenCCK\Domain\Repository\SkillUsageRepository $usage): void {
        $this->usage = $usage;
    }

    /** A skill not opened for this many days (or since it was made) is named for /skill off or pin. */
    public const STALE_DAYS = 30;
    private const BUILTIN_PLACE = 'builtin';

    /** Whether the use of skills is counted: pin, off and the marks need it. */
    public function counts(): bool {
        return $this->usage !== null;
    }

    /** The key of the place of a skill for its use: built in, or the owner and the folder of the skills. */
    private static function place(?int $owner, ?string $dir): string {
        return $owner === null || $dir === null ? self::BUILTIN_PLACE : $owner . ':' . dirname($dir);
    }

    /**
     * The use of each skill of this conversation, by the place the skill comes from. A folder skill without a row gets
     * one now: it is first seen now, and it can grow stale from then.
     *
     * spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — the key of the use is the place and the name
     * ref: references/hermes-agent/agent/curator.py@e96221fa:L234-L235 — a never-active skill is anchored on when it
     *      was made or first seen, so it does not go stale at once nor stay fresh for ever
     *
     * @param array<string, array{description: string, where: string, dir: ?string, owner: ?int}>|null $catalog
     * @return array<string, array{views: int, last: ?int, created: int, pinned: bool, off: bool}>
     */
    public function stats(Principal $who, ?Project $project, ?array $catalog = null): array {
        if ($this->usage === null) {
            return [];
        }
        $all = $this->usage->stats();
        $out = [];
        foreach ($catalog ?? $this->catalog($who, $project) as $name => $skill) {
            $place = self::place($skill['owner'], $skill['dir']);
            $use = $all[$place][$name] ?? $all[''][$name] ?? null;
            if ($use === null && $place !== self::BUILTIN_PLACE) {
                try {
                    $this->usage->created($place, $name);
                } catch (Throwable) {
                }
                $use = ['views' => 0, 'last' => null, 'created' => time(), 'pinned' => false, 'off' => false];
            }
            if ($use !== null) {
                $out[$name] = $use;
            }
        }
        return $out;
    }

    /**
     * Whether a skill not opened for STALE_DAYS (and not pinned or off) waits for /skill off or pin.
     *
     * @param array{views: int, last: ?int, created: int, pinned: bool, off: bool} $use
     */
    public static function stale(array $use, ?int $now = null): bool {
        return !$use['pinned'] && !$use['off'] && ($use['last'] ?? $use['created']) < ($now ?? time()) - self::STALE_DAYS * 86400;
    }

    /**
     * Skills of this conversation nobody opened for STALE_DAYS, for the review of the owner.
     *
     * @return list<string>
     */
    public function unused(Principal $who, ?Project $project): array {
        return array_keys(array_filter($this->stats($who, $project), static fn(array $use): bool => self::stale($use)));
    }

    /** Pins a skill (never marked stale) or turns it off (out of the index); false — no such skill or no count. */
    public function mark(Principal $who, ?Project $project, string $name, string $mark, bool $on): bool {
        $skill = $this->catalog($who, $project)[$name] ?? null;
        if ($skill === null || $this->usage === null) {
            return false;
        }
        $place = self::place($skill['owner'], $skill['dir']);
        $mark === 'pin' ? $this->usage->pin($place, $name, $on) : $this->usage->turnOff($place, $name, $on);
        return true;
    }

    /**
     * Removes a skill's folder after $before took a snapshot of the owner's working folder; null when removed, else why
     * not. A built-in skill is in the code and can only be turned off.
     *
     * spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — /skill <name> remove, through a snapshot
     *
     * @param Closure(int, SandboxLimits): void $before
     */
    public function remove(Principal $who, ?Project $project, string $name, Closure $before, Cancellation $cancellation): ?string {
        $skill = $this->catalog($who, $project, $cancellation)[$name] ?? null;
        if ($skill === null) {
            return sprintf(t('service.skill_commands.skilla_net_skills_spisok'), $name);
        }
        $limits = $skill['owner'] === null ? null : ($this->limitsOf)($skill['owner']);
        if ($skill['dir'] === null || $skill['owner'] === null || $limits === null || $this->sandbox === null) {
            return sprintf(t('skill.library.builtin_off_only'), $name);
        }
        $before($skill['owner'], $limits);
        $outcome = $this->sandbox->exec($skill['owner'], $limits, 'skill-remove-' . $skill['owner'], "rm -rf -- '" . str_replace("'", '', $skill['dir']) . "'", '.', 30, $cancellation);
        return $outcome->exitCode === 0 ? null : trim($outcome->stderr);
    }

    /** The folder of a project's skills, in the sandbox of the project's owner. */
    public static function projectDir(Project $project): string {
        return 'projects/' . $project->id . '/' . self::MINE;
    }

    /**
     * The folders a conversation reads, the nearest first: the active project's, then the participant's own.
     *
     * @return list<array{dir: string, owner: int, label: string}>
     */
    public function places(Principal $who, ?Project $project): array {
        if (!$who->role->may(Power::Skills)) {
            return [];
        }
        $places = [];
        if ($project !== null && $project->visibleTo($who)) {
            $owner = $project->ownerId ?? ($this->ownerId)();
            if ($owner !== null) {
                $places[] = ['dir' => self::projectDir($project), 'owner' => $owner, 'label' => sprintf(t('skill.library.project'), $project->name)];
            }
        }
        $places[] = ['dir' => self::MINE, 'owner' => $who->id, 'label' => t('skill.library.mine')];
        return $places;
    }

    /**
     * Every skill the conversation can use, the nearest place winning.
     *
     * @return array<string, array{description: string, where: string, dir: ?string, owner: ?int}>
     */
    public function catalog(Principal $who, ?Project $project, ?Cancellation $cancellation = null): array {
        $found = [];
        foreach ($this->builtin as $name => $bundle) {
            $found[$name] = ['description' => $bundle->description, 'where' => t('skill.library.builtin'), 'dir' => null, 'owner' => null];
        }
        foreach (array_reverse($this->places($who, $project)) as $place) {
            foreach ($this->read($place['owner'], $place['dir'], $cancellation ?? new NullCancellation()) as $name => $description) {
                $found[$name] = ['description' => $description, 'where' => $place['label'], 'dir' => $place['dir'] . '/' . $name, 'owner' => $place['owner']];
            }
        }
        ksort($found);
        return $found;
    }

    /** The index for the system prompt; empty when there are no skills. */
    public function index(Principal $who, ?Project $project): string {
        if (!$who->role->may(Power::Skills)) {
            return '';
        }
        $catalog = $this->catalog($who, $project);
        if ($catalog === []) {
            return '';
        }
        $stats = $this->stats($who, $project, $catalog);
        $lines = [];
        foreach ($catalog as $name => $skill) {
            if (!($stats[$name]['off'] ?? false)) {
                $lines[] = sprintf('- %s: %s [%s]', $name, $skill['description'], $skill['where']);
            }
        }
        return $lines === [] ? '' : t('skill.library.index') . "\n" . implode("\n", $lines);
    }

    /**
     * The text of a skill, or one of its files; null when the conversation has no such skill or file.
     *
     * @return array{text: string, files: list<string>}|null
     */
    public function view(Principal $who, ?Project $project, string $name, ?string $file, Cancellation $cancellation): ?array {
        $skill = $this->catalog($who, $project, $cancellation)[$name] ?? null;
        if ($skill === null) {
            return null;
        }
        $shown = $this->show($skill, $name, $file, $cancellation);
        if ($shown !== null && $file === null) {
            // ref: references/hermes-agent/tools/skill_usage.py@e96221fa:L1-L6 — a counter bump is best effort
            try {
                $this->usage?->touch(self::place($skill['owner'], $skill['dir']), $name);
            } catch (Throwable) {
            }
        }
        return $shown;
    }

    /**
     * @param array{description: string, where: string, dir: ?string, owner: ?int} $skill
     * @return array{text: string, files: list<string>}|null
     */
    private function show(array $skill, string $name, ?string $file, Cancellation $cancellation): ?array {
        if ($skill['dir'] === null || $skill['owner'] === null) {
            $bundle = $this->builtin[$name];
            if ($file !== null) {
                return isset($bundle->files[$file]) ? ['text' => $bundle->files[$file], 'files' => []] : null;
            }
            return ['text' => $bundle->body, 'files' => array_keys($bundle->files)];
        }
        $limits = ($this->limitsOf)($skill['owner']);
        if ($this->sandbox === null || $limits === null) {
            return null;
        }
        try {
            if ($file !== null) {
                // spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — each file of a folder skill passes the check when read
                $text = $this->sandbox->fetch($skill['owner'], $limits, $skill['dir'] . '/' . $file, SkillBundle::READ_BYTES, $cancellation);
                if (SkillGuard::blocks(SkillGuard::scan(SkillBundle::fromFiles(['SKILL.md' => "---\nname: x\n---\n", $file => $text], 'folder', '')))) {
                    $this->logger->warning('A file of a folder skill is refused by the check', ['dir' => $skill['dir'], 'file' => $file]);
                    return null;
                }
                return ['text' => $text, 'files' => []];
            }
            $raw = $this->sandbox->fetch($skill['owner'], $limits, $skill['dir'] . '/SKILL.md', SkillBundle::READ_BYTES, $cancellation);
            // ref: references/hermes-agent/agent/skill_utils.py@e96221fa:L511-L515 — skills of a folder are a way to
            //      inject instructions; hermes loads project ones only from trusted roots, here every folder skill is
            //      checked like an imported one when it is read, and a dangerous one is not given
            if (SkillGuard::blocks(SkillGuard::scan(SkillBundle::fromFiles(['SKILL.md' => $raw], 'folder', '')))) {
                $this->logger->warning('A folder skill is refused by the check', ['dir' => $skill['dir']]);
                return null;
            }
            $text = SkillBundle::head($raw)['body'];
            $files = [];
            foreach ($this->sandbox->list($skill['owner'], $limits, $skill['dir'], 3, $cancellation) as $entry) {
                $path = $entry['path'];
                if ($entry['type'] === 'file' && $path !== 'SKILL.md' && $path !== '' && !str_starts_with($path, '.')) {
                    $files[] = $path;
                }
            }
            return ['text' => $text, 'files' => $files];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Where a skill is written: `mine` — the participant's folder; `project` — the active project's, only for the
     * project's owner. Null when there is no such place for them.
     *
     * @return array{owner: int, dir: string, limits: SandboxLimits}|null
     */
    public function target(Principal $who, ?Project $project, string $where): ?array {
        if ($this->sandbox === null || !$who->role->may(Power::Skills)) {
            return null;
        }
        $owner = $who->id;
        $dir = self::MINE;
        if ($where === 'project') {
            if ($project === null || ($project->ownerId ?? ($this->ownerId)()) !== $who->id) {
                return null;
            }
            $dir = self::projectDir($project);
        }
        $limits = ($this->limitsOf)($owner);
        return $limits === null ? null : ['owner' => $owner, 'dir' => $dir, 'limits' => $limits];
    }

    /**
     * Writes a skill into a folder as a directory: SKILL.md (frontmatter and text) and its files. What was in that
     * directory under the same paths is replaced; the snapshots of the working folder keep the earlier text.
     *
     * @param array{owner: int, dir: string, limits: SandboxLimits} $target
     * @param string $source who edits: skill_save, skill_import, /skill import — for the journal
     * @param string|null $from the address a skill was imported by: kept with its revision in SKILL.md for /skill update
     */
    public function write(SkillBundle $bundle, array $target, Cancellation $cancellation, string $source = '', ?string $from = null): string {
        if ($this->sandbox === null) {
            throw new \RuntimeException('no sandbox');
        }
        $root = $target['dir'] . '/' . $bundle->name;
        $before = $this->edits === null ? null : $this->folder($target['owner'], $target['limits'], $root, $cancellation);
        // spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — an imported skill keeps where it came from and which revision it is
        $origin = $from === null || preg_match('~[\r\n]~', $from . $bundle->revision) === 1 ? '' : "source: " . $from . "\nrevision: " . $bundle->revision . "\n";
        $skillMd = "---\nname: " . $bundle->name . "\ndescription: " . str_replace("\n", ' ', $bundle->description) . "\n" . $origin . "---\n\n" . $bundle->body . "\n";
        $this->sandbox->write($target['owner'], $target['limits'], $root . '/SKILL.md', $skillMd, $cancellation);
        foreach ($bundle->files as $path => $text) {
            $this->sandbox->write($target['owner'], $target['limits'], $root . '/' . $path, $text, $cancellation);
        }
        $this->edits?->record($target['owner'], $root, $bundle->name, $before, $source);
        // spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — a skill has a row of its use from when it is made
        try {
            $this->usage?->created(self::place($target['owner'], $root), $bundle->name);
        } catch (Throwable) {
        }
        return $root;
    }

    /**
     * Skills of one folder: its directories that hold a SKILL.md whose name agrees with the directory.
     *
     * @return array<string, string> name => description
     */
    private function read(int $owner, string $dir, Cancellation $cancellation): array {
        $limits = ($this->limitsOf)($owner);
        if ($this->sandbox === null || $limits === null) {
            return [];
        }
        try {
            $entries = $this->sandbox->list($owner, $limits, $dir, 2, $cancellation);
        } catch (Throwable) {
            return []; // no folder yet
        }
        $found = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'file' || preg_match('~^([^/]+)/SKILL\.md$~', $entry['path'], $m) !== 1 || count($found) >= self::PER_PLACE) {
                continue;
            }
            $folder = strtolower($m[1]);
            try {
                $raw = $this->sandbox->fetch($owner, $limits, $dir . '/' . $m[1] . '/SKILL.md', SkillBundle::READ_BYTES, $cancellation);
                $head = SkillBundle::head($raw);
            } catch (Throwable $e) {
                $this->logger->notice('A skill was not read', ['dir' => $dir . '/' . $m[1], 'error' => $e::class]);
                continue;
            }
            // spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — the description goes into the prompt: a dangerous skill
            //       is not in the index at all
            if (SkillGuard::blocks(SkillGuard::scan(SkillBundle::fromFiles(['SKILL.md' => $raw], 'folder', '')))) {
                $this->logger->warning('A folder skill is left out of the index by the check', ['dir' => $dir . '/' . $m[1]]);
                continue;
            }
            $name = $head['name'] !== '' ? $head['name'] : $folder;
            if ($name !== $folder || preg_match(\OpenCCK\Domain\Skill\Skill::NAME_PATTERN, $name) !== 1) {
                continue; // a skill is found by its folder: the folder and the name must agree
            }
            $found[$name] = $head['description'];
        }
        return $found;
    }
}
