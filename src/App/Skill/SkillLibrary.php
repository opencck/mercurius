<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use Amp\NullCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Where the agent's skills are: all are ordinary directories with a SKILL.md and files next to it, in three places —
 * - built-in: `skills/<name>/` of the code (submodules), the same everywhere, read-only;
 * - the participant's: `skills/<name>/` in their sandbox folder — for their private chat with the agent;
 * - the project's: `projects/<number>/skills/<name>/` in the sandbox folder of the project's owner — for every chat
 *   and forum topic the project is active in.
 * They add up; the same name in a nearer place wins (project → participant → built-in), like the tiers of hermes
 * (references/hermes-agent/agent/skill_utils.py@e96221fa:L400-L423, get_skill_search_roots: project > local > …). A skill moves between places as
 * a directory: a built-in one copied into a folder can be changed there; a folder's skill can become a submodule.
 *
 * spec: BRIEF.md#скиллы-и-mcp — skills are files: built-in ones plus the folders of the participant and the project
 */
final class SkillLibrary {
    public const MINE = 'skills';
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

    /** The folder of a project's skills, in the sandbox of the project's owner. */
    public static function projectDir(Project $project): string {
        return 'projects/' . $project->id . '/skills';
    }

    /**
     * The folders a conversation reads, the nearest first: the active project's, then the participant's own.
     *
     * @return list<array{dir: string, owner: int, label: string}>
     */
    public function places(Principal $who, ?Project $project): array {
        if ($who->role === Role::visitor()) {
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
        if ($who->role === Role::visitor()) {
            return '';
        }
        $catalog = $this->catalog($who, $project);
        if ($catalog === []) {
            return '';
        }
        $lines = [];
        foreach ($catalog as $name => $skill) {
            $lines[] = sprintf('- %s: %s [%s]', $name, $skill['description'], $skill['where']);
        }
        return t('skill.library.index') . "\n" . implode("\n", $lines);
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
                // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — each file of a folder skill passes the check when read
                $text = $this->sandbox->fetch($skill['owner'], $limits, $skill['dir'] . '/' . $file, SkillBundle::MAX_FILE_BYTES, $cancellation);
                if (SkillGuard::blocks(SkillGuard::scan(SkillBundle::fromFiles(['SKILL.md' => "---\nname: x\n---\n", $file => $text], 'folder', '')))) {
                    $this->logger->warning('A file of a folder skill is refused by the check', ['dir' => $skill['dir'], 'file' => $file]);
                    return null;
                }
                return ['text' => $text, 'files' => []];
            }
            $raw = $this->sandbox->fetch($skill['owner'], $limits, $skill['dir'] . '/SKILL.md', SkillBundle::MAX_FILE_BYTES, $cancellation);
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
        if ($this->sandbox === null || $who->role === Role::visitor()) {
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
     */
    public function write(SkillBundle $bundle, array $target, Cancellation $cancellation): string {
        if ($this->sandbox === null) {
            throw new \RuntimeException('no sandbox');
        }
        $root = $target['dir'] . '/' . $bundle->name;
        $skillMd = "---\nname: " . $bundle->name . "\ndescription: " . str_replace("\n", ' ', $bundle->description) . "\n---\n\n" . $bundle->body . "\n";
        $this->sandbox->write($target['owner'], $target['limits'], $root . '/SKILL.md', $skillMd, $cancellation);
        foreach ($bundle->files as $path => $text) {
            $this->sandbox->write($target['owner'], $target['limits'], $root . '/' . $path, $text, $cancellation);
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
                $raw = $this->sandbox->fetch($owner, $limits, $dir . '/' . $m[1] . '/SKILL.md', SkillBundle::MAX_FILE_BYTES, $cancellation);
                $head = SkillBundle::head($raw);
            } catch (Throwable $e) {
                $this->logger->notice('A skill was not read', ['dir' => $dir . '/' . $m[1], 'error' => $e::class]);
                continue;
            }
            // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the description goes into the prompt: a dangerous skill
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
