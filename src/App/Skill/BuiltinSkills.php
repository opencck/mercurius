<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use InvalidArgumentException;
use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\Skill;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Built-in skills ship with the code: each is a repository, a submodule in `skills/<name>/` with an ordinary
 * SKILL.md and its files. At the start every one goes into the catalog as an active version without a card: it was
 * reviewed like the code it ships with. A changed skill becomes a new version (history and rollback stay), an
 * unchanged one is left alone; the repository's README, licence and CI are not part of the skill.
 *
 * spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — every built-in skill is its own repository, a submodule in skills/
 * ref: references/hermes-agent/optional-skills/software-development/hermes-agent-skill-authoring/SKILL.md@e96221fa:L16-L22
 *      — hermes ships skills in its repository tree; here each in a repository of its own
 */
final class BuiltinSkills {
    /** files of the repository, not of the skill */
    private const NOT_SKILL = ['README.md', 'README.ru.md', 'LICENSE', 'composer.json', 'composer.lock', 'package.json'];

    public function __construct(
        private readonly SkillCatalog $catalog,
        private readonly SkillRepository $skills,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @return list<string> the skills that got a new version */
    public function sync(string $directory): array {
        if (!is_dir($directory)) {
            return [];
        }
        $changed = [];
        $entries = scandir($directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            $root = $directory . '/' . $entry;
            if (str_starts_with($entry, '.') || !is_file($root . '/SKILL.md')) {
                continue;
            }
            try {
                $bundle = SkillBundle::fromFiles(self::read($root), 'builtin:' . $entry, '');
            } catch (InvalidArgumentException $e) {
                $this->logger->warning('Built-in skill is not a skill', ['skill' => $entry, 'error' => $e->getMessage()]);
                continue;
            }
            $current = $this->skills->find($bundle->name);
            if ($current !== null && $current->status === Skill::DISABLED) {
                continue; // the owner turned it off: a start does not turn it on again
            }
            if ($current !== null && $current->status !== Skill::DRAFT && $current->body === $bundle->body
                && $current->description === $bundle->description && $this->skills->files($bundle->name) == $bundle->files) {
                continue;
            }
            $version = $this->catalog->propose($bundle->name, $bundle->description, $bundle->body, 'built-in skill', null, 'builtin');
            $this->skills->attachFiles($version->id, $bundle->files);
            $this->skills->accept($version->id);
            $changed[] = $bundle->name;
            $this->logger->info('Built-in skill updated', ['skill' => $bundle->name, 'version' => $version->version]);
        }
        return $changed;
    }

    /** @return array<string, string> path inside the skill => bytes */
    private static function read(string $root): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($path, self::NOT_SKILL, true) || str_starts_with($path, '.') || str_contains($path, '/.')) {
                continue;
            }
            $files[$path] = (string) file_get_contents($file->getPathname());
        }
        return $files;
    }
}
