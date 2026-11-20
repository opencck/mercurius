<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Built-in skills ship with the code: each is a repository, a submodule in `skills/<name>/` with an ordinary SKILL.md
 * and its files — the same format as a skill in a sandbox folder or one imported from GitHub or an archive. They are
 * read at the start and served as they are, everywhere; nothing is copied, so updating the code brings no conflicts:
 * a changed copy lives in a folder of the participant or the project and wins over the built-in one by its name.
 * The repository's README, BRIEF.md, licence and CI are not part of the skill.
 *
 * spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — built-in skills plus the folders of the participant and the project
 * ref: references/hermes-agent/optional-skills/software-development/hermes-agent-skill-authoring/SKILL.md@e96221fa:L16-L22
 *      — hermes ships skills in its repository tree; here each in a repository of its own, a submodule
 */
final class BuiltinSkills {
    /** files of the repository, not of the skill */
    private const NOT_SKILL = ['README.md', 'README.ru.md', 'BRIEF.md', 'LICENSE', 'composer.json', 'composer.lock', 'package.json'];

    /** @return array<string, SkillBundle> name => skill */
    public static function load(string $directory, LoggerInterface $logger = new NullLogger()): array {
        if (!is_dir($directory)) {
            return [];
        }
        $skills = [];
        $entries = scandir($directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            $root = $directory . '/' . $entry;
            if (str_starts_with($entry, '.') || !is_file($root . '/SKILL.md')) {
                continue;
            }
            try {
                $bundle = SkillBundle::fromFiles(self::read($root), 'builtin:' . $entry, '');
            } catch (InvalidArgumentException $e) {
                $logger->warning('Built-in skill is not a skill', ['skill' => $entry, 'error' => $e->getMessage()]);
                continue;
            }
            $skills[$bundle->name] = $bundle;
        }
        ksort($skills);
        return $skills;
    }

    /** @return array<string, string> path inside the skill => bytes */
    public static function read(string $root): array {
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
