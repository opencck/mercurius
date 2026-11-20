<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use InvalidArgumentException;
use PharData;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

use function Mercurius\I18n\t;

/**
 * A skill packed in an archive at an address: .zip, .tar.gz or .tgz. The directory with SKILL.md closest to the root
 * is the skill; everything outside it is left out. The archive is read from memory or a temporary file and never
 * unpacked onto the disk, so a path inside it cannot reach anything.
 *
 * spec: brief/skills-mcp.md#скиллы-и-mcp — a skill from git or an archive
 */
final class ArchiveSkillSource implements SkillSource {
    /** @param int $maxFiles the owner's cap on the files of a skill (SKILL_MAX_FILES); 0 — none */
    public function __construct(private readonly HttpClient $http, private readonly int $maxFiles = 0) {}

    public function supports(string $url): bool {
        return preg_match('~^https://\S+\.(?:zip|tar\.gz|tgz)(?:\?\S*)?$~i', trim($url)) === 1;
    }

    public function fetch(string $url, Cancellation $cancellation): SkillBundle {
        $request = new Request(trim($url));
        // no size limit (owner's decision 2026-11-19)
        $request->setBodySizeLimit(0);
        $request->setHeader('User-Agent', 'mercurius-agent');
        $response = $this->http->request($request, $cancellation);
        $bytes = $response->getBody()->buffer($cancellation);
        if ($response->getStatus() !== 200) {
            throw new InvalidArgumentException(sprintf(t('skill.import.http_failed'), $response->getStatus(), $url));
        }
        return self::bundle($bytes, $url, trim($url), $this->maxFiles);
    }

    /** The archive's file name tells zip from tar.gz; too big, not an archive or no SKILL.md — refused. */
    public static function supportsName(string $name): bool {
        return preg_match('~\.(?:zip|tar\.gz|tgz)$~i', trim($name)) === 1;
    }

    /**
     * A skill from the bytes of an archive the agent already has (sent to the chat, made in the sandbox).
     *
     * @throws InvalidArgumentException
     */
    public static function bundle(string $bytes, string $name, string $source, int $maxFiles = 0): SkillBundle {
        $entries = preg_match('~\.zip(?:\?|$)~i', $name) === 1 ? self::zip($bytes) : self::tar($bytes);
        return SkillBundle::fromFiles(self::skillDirectory($entries), $source, hash('sha256', $bytes), $maxFiles);
    }

    /** @return array<string, string> */
    private static function zip(string $bytes): array {
        $file = tempnam(sys_get_temp_dir(), 'skill');
        if ($file === false) {
            throw new InvalidArgumentException(t('skill.import.bad_archive'));
        }
        try {
            file_put_contents($file, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw new InvalidArgumentException(t('skill.import.bad_archive'));
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                /** @var mixed $name */
                $name = $stat === false ? null : $stat['name'];
                /** @var mixed $size */
                $size = $stat === false ? null : $stat['size'];
                if (!is_string($name) || !is_int($size) || str_ends_with($name, '/')) {
                    continue;
                }
                $content = $zip->getFromIndex($i);
                if (is_string($content)) {
                    $entries[$name] = $content;
                }
            }
            $zip->close();
            return $entries;
        } finally {
            @unlink($file);
        }
    }

    /** @return array<string, string> */
    private static function tar(string $bytes): array {
        $file = tempnam(sys_get_temp_dir(), 'skill');
        if ($file === false) {
            throw new InvalidArgumentException(t('skill.import.bad_archive'));
        }
        $path = $file . '.tar.gz';
        try {
            file_put_contents($path, $bytes);
            $entries = [];
            $root = 'phar://' . str_replace('\\', '/', $path) . '/';
            /** @var \PharFileInfo $info */
            foreach (new RecursiveIteratorIterator(new PharData($path)) as $info) {
                if ($info->isLink() || !$info->isFile()) {
                    continue;
                }
                $content = file_get_contents($info->getPathname());
                if (is_string($content)) {
                    $entries[substr(str_replace('\\', '/', $info->getPathname()), strlen($root))] = $content;
                }
            }
            return $entries;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidArgumentException(t('skill.import.bad_archive'));
        } finally {
            @unlink($path);
            @unlink($file);
        }
    }

    /**
     * The files of the directory holding the shallowest SKILL.md, relative to it.
     *
     * @param array<string, string> $entries
     * @return array<string, string>
     */
    private static function skillDirectory(array $entries): array {
        $dir = null;
        foreach (array_keys($entries) as $path) {
            $path = str_replace('\\', '/', self::key($path));
            if ($path === 'SKILL.md' || str_ends_with($path, '/SKILL.md')) {
                $candidate = substr($path, 0, -strlen('SKILL.md'));
                if ($dir === null || substr_count($candidate, '/') < substr_count($dir, '/')) {
                    $dir = $candidate;
                }
            }
        }
        if ($dir === null) {
            throw new InvalidArgumentException(t('skill.bundle.no_skill_md'));
        }
        $files = [];
        foreach ($entries as $path => $content) {
            $path = str_replace('\\', '/', self::key($path));
            if ($dir === '' || str_starts_with($path, $dir)) {
                $files[substr($path, strlen($dir))] = $content;
            }
        }
        return $files;
    }

    /** An array key as text: PHP turns a file named "1" into an integer key. */
    private static function key(int|string $key): string {
        return (string) $key;
    }
}
