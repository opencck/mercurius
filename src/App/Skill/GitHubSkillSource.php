<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use InvalidArgumentException;

use function Mercurius\I18n\t;

/**
 * A skill directory in a GitHub repository: https://github.com/<owner>/<repo>/tree/<ref>/<path>. The revision is
 * resolved first and every file is read at it, so the files are the ones the tree listed; symbolic links and paths
 * outside the directory are not followed.
 *
 * ref: references/hermes-agent/tools/skills_hub_github.py@e96221fa:L292-L387 (fetch, _collect_tree_files) — the tree
 *      pinned to one revision, the whole directory downloaded, symlinks rejected; hermes reads blobs through the
 *      Contents API, here raw files at the commit
 */
final class GitHubSkillSource implements SkillSource {
    private const API = 'https://api.github.com/repos/';
    private const RAW = 'https://raw.githubusercontent.com/';
    /** How many skills of a set the answer names */
    private const NESTED_MAX = 40;

    /** @param int $maxFiles the owner's cap on the files of a skill (SKILL_MAX_FILES); 0 — none */
    public function __construct(private readonly HttpClient $http, private readonly int $maxFiles = 0) {}

    public function supports(string $url): bool {
        return self::parse($url) !== null;
    }

    public function fetch(string $url, Cancellation $cancellation): SkillBundle {
        [$repo, $ref, $dir] = self::parse($url) ?? throw new InvalidArgumentException(t('skill.import.bad_github'));
        $commit = $this->json(self::API . $repo . '/commits/' . rawurlencode($ref), $cancellation);
        /** @var mixed $sha */
        $sha = $commit['sha'] ?? null;
        /** @var mixed $info */
        $info = $commit['commit'] ?? null;
        /** @var mixed $treeInfo */
        $treeInfo = is_array($info) ? ($info['tree'] ?? null) : null;
        /** @var mixed $treeSha */
        $treeSha = is_array($treeInfo) ? ($treeInfo['sha'] ?? null) : null;
        if (!is_string($sha) || !is_string($treeSha)) {
            throw new InvalidArgumentException(t('skill.import.no_revision'));
        }
        $tree = $this->json(self::API . $repo . '/git/trees/' . $treeSha . '?recursive=1', $cancellation);
        /** @var mixed $entries */
        $entries = $tree['tree'] ?? null;
        if (!is_array($entries)) {
            throw new InvalidArgumentException(t('skill.import.no_revision'));
        }
        $prefix = $dir === '' ? '' : $dir . '/';
        $paths = [];
        /** @var mixed $entry */
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? null) !== 'blob' || ($entry['mode'] ?? null) === '120000') {
                continue;
            }
            /** @var mixed $path */
            $path = $entry['path'] ?? null;
            // dotfiles and bytecode never ship: they are not downloaded or counted (hermes _skip_bundle_file)
            if (is_string($path) && str_starts_with($path, $prefix) && preg_match('~(^|/)(\.[^/]*|__pycache__)(/|$)|\.pyc$~', substr($path, strlen($prefix))) !== 1) {
                $paths[] = $path;
            }
        }
        $nested = self::nested($paths, $prefix);
        if ($nested !== []) {
            // a set of skills, not a skill: nothing is downloaded, the answer names where the skills are
            $base = sprintf('https://github.com/%s/tree/%s/%s', $repo, $ref, $prefix);
            throw new InvalidArgumentException(sprintf(t('skill.import.nested'), implode(', ', array_map(static fn(string $at): string => $base . $at, $nested))));
        }
        SkillBundle::checkCount(count($paths), $this->maxFiles);
        $files = [];
        foreach ($paths as $path) {
            $files[substr($path, strlen($prefix))] = $this->body(self::RAW . $repo . '/' . $sha . '/' . implode('/', array_map('rawurlencode', explode('/', $path))), $cancellation);
        }
        return SkillBundle::fromFiles($files, sprintf('https://github.com/%s/tree/%s%s', $repo, $sha, $dir === '' ? '' : '/' . $dir), $sha, $this->maxFiles);
    }

    /**
     * The directories under the prefix that are skills, when the prefix itself is not one: a repository often holds a
     * set of skills (skills/<name>/SKILL.md), and the answer names them instead of a bare refusal.
     *
     * @param list<string> $paths files of the tree
     * @return list<string> directories relative to the prefix; empty when the prefix is a skill or holds none
     */
    public static function nested(array $paths, string $prefix): array {
        $found = [];
        foreach ($paths as $path) {
            $inside = substr($path, strlen($prefix));
            if ($inside === 'SKILL.md') {
                return [];
            }
            if (str_ends_with($inside, '/SKILL.md')) {
                $found[] = substr($inside, 0, -strlen('/SKILL.md'));
            }
        }
        sort($found);
        return array_slice($found, 0, self::NESTED_MAX);
    }

    /** @return array{string, string, string}|null owner/repo, ref, directory */
    private static function parse(string $url): ?array {
        if (preg_match('~^https://github\.com/([\w.-]+/[\w.-]+?)(?:\.git)?(?:/tree/([^/\s]+)(?:/([^\s?#]*))?)?/?$~', trim($url), $m) !== 1) {
            return null;
        }
        return [$m[1], ($m[2] ?? '') !== '' ? $m[2] : 'HEAD', trim($m[3] ?? '', '/')];
    }

    /** @return array<array-key, mixed> */
    private function json(string $url, Cancellation $cancellation): array {
        /** @var mixed $data */
        $data = json_decode($this->body($url, $cancellation, 'application/vnd.github+json', 8 * 1024 * 1024), true, 64, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    /** @param int $limit bytes of the body; 0 — no limit (files of a skill) */
    private function body(string $url, Cancellation $cancellation, string $accept = '*/*', int $limit = 0): string {
        $request = new Request($url);
        $request->setHeader('Accept', $accept);
        $request->setHeader('User-Agent', 'mercurius-agent');
        // a skill has no size limit; the listing is bounded
        $request->setBodySizeLimit($limit);
        $response = $this->http->request($request, $cancellation);
        $body = $response->getBody()->buffer($cancellation);
        if ($response->getStatus() !== 200) {
            throw new InvalidArgumentException(sprintf(t('skill.import.http_failed'), $response->getStatus(), $url));
        }
        return $body;
    }
}
