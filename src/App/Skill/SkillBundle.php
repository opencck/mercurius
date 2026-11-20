<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use InvalidArgumentException;
use OpenCCK\Domain\Skill\Skill;

use function Mercurius\I18n\t;

/**
 * An ordinary skill of another agent: SKILL.md (frontmatter with name and description, then the text) and the files
 * next to it — references/, scripts/ and others. Only UTF-8 text is kept; binary files are listed as skipped.
 *
 * spec: brief/skills-mcp.md#скиллы-других-агентов-импорт — ordinary SKILL.md skills of other agents work here
 * ref: references/hermes-agent/tools/skills_hub_github.py@e96221fa:L190-L205 (_skip_bundle_file, _tree_members) —
 *      dotfiles, bytecode and __pycache__ never ship; a path leaving the skill rejects the bundle
 * ref: references/hermes-agent/tools/skills_guard.py@e96221fa:L614-L615 — hermes caps 50 files, 256 KB a file, 5 MB in
 *      all; here no cap by default (owner's decision 2026-11-19: a skill of 140 files was refused), the owner may cap the
 *      number of files with SKILL_MAX_FILES
 */
final class SkillBundle {
    /** how much of a skill file the agent reads at once: a reading limit, not an import one */
    public const READ_BYTES = 256 * 1024;

    /**
     * @param array<string, string> $files relative path => text, without SKILL.md
     * @param list<string> $skipped binary files that were not kept
     */
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $body,
        public readonly array $files,
        public readonly array $skipped,
        public readonly string $source,
        public readonly string $revision,
    ) {}

    /**
     * @param array<string, string> $raw path inside the skill directory => bytes
     * @param int $maxFiles the owner's cap on the number of files (SKILL_MAX_FILES); 0 — none
     * @throws InvalidArgumentException when it is not a skill or has more files than the cap
     */
    public static function fromFiles(array $raw, string $source, string $revision, int $maxFiles = 0): self {
        $files = [];
        $skipped = [];
        foreach ($raw as $path => $bytes) {
            $path = self::path(self::key($path));
            if ($path === null) {
                continue;
            }
            if (str_contains($bytes, "\0") || !mb_check_encoding($bytes, 'UTF-8')) {
                $skipped[] = $path;
                continue;
            }
            $files[$path] = $bytes;
        }
        self::checkCount(count($files) + count($skipped), $maxFiles);
        $skill = $files['SKILL.md'] ?? null;
        if ($skill === null) {
            throw new InvalidArgumentException(t('skill.bundle.no_skill_md'));
        }
        unset($files['SKILL.md']);
        [$meta, $body] = self::frontmatter($skill);
        $name = strtolower(trim($meta['name'] ?? ''));
        if (preg_match(Skill::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(t('skill.bundle.bad_name'), $name));
        }
        ksort($files);
        sort($skipped);
        return new self($name, self::oneLine($meta['description'] ?? ''), trim($body), $files, $skipped, $source, $revision);
    }

    /**
     * The owner's cap on the number of files of a skill; 0 — none.
     *
     * spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — SKILL_MAX_FILES
     *
     * @throws InvalidArgumentException
     */
    public static function checkCount(int $files, int $maxFiles): void {
        if ($maxFiles > 0 && $files > $maxFiles) {
            throw new InvalidArgumentException(sprintf(t('skill.bundle.too_many'), $maxFiles));
        }
    }

    /**
     * Where an imported skill came from: the address it was imported by and the revision then; empty when not imported.
     *
     * spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — the source and revision of an import, for /skill update
     *
     * @return array{source: string, revision: string}
     */
    public static function origin(string $skillMd): array {
        [$meta] = self::frontmatter($skillMd);
        return ['source' => $meta['source'] ?? '', 'revision' => $meta['revision'] ?? ''];
    }

    /**
     * The name, the one-line description and the text of a SKILL.md, for the index and for reading.
     *
     * @return array{name: string, description: string, body: string}
     */
    public static function head(string $skillMd): array {
        [$meta, $body] = self::frontmatter($skillMd);
        return ['name' => strtolower(trim($meta['name'] ?? '')), 'description' => self::oneLine($meta['description'] ?? ''), 'body' => trim($body)];
    }

    /** A clean relative path; null for what never ships (dotfiles, bytecode); an escape is refused. */
    private static function path(string $path): ?string {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1) {
            throw new InvalidArgumentException(sprintf(t('skill.bundle.bad_path'), $path));
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InvalidArgumentException(sprintf(t('skill.bundle.bad_path'), $path));
            }
            if (str_starts_with($part, '.') || $part === '__pycache__') {
                return null;
            }
            $parts[] = $part;
        }
        $clean = implode('/', $parts);
        return $clean === '' || str_ends_with($clean, '.pyc') ? null : $clean;
    }

    /**
     * The YAML frontmatter as plain "key: value" lines (folded and quoted values included); the rest is the text.
     *
     * @return array{array<string, string>, string}
     */
    private static function frontmatter(string $text): array {
        $text = str_replace("\r\n", "\n", $text);
        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $text, $m) !== 1) {
            return [[], $text];
        }
        $meta = [];
        $key = null;
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', $line, $kv) === 1) {
                $key = strtolower($kv[1]);
                $value = trim($kv[2]);
                $meta[$key] = in_array($value, ['>', '|', '>-', '|-'], true) ? '' : trim($value, "\"'");
            } elseif ($key !== null && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $meta[$key] = trim($meta[$key] . ' ' . trim($line));
            }
        }
        return [$meta, $m[2]];
    }

    /** The description for the index: one line, cut on a word. */
    private static function oneLine(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= Skill::DESCRIPTION_MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, Skill::DESCRIPTION_MAX - 1);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,.;:') . '…';
    }

    /** An array key as text: PHP turns a file named "1" into an integer key. */
    private static function key(int|string $key): string {
        return (string) $key;
    }
}
