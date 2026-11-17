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

    public function __construct(private readonly HttpClient $http) {}

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
            if (is_string($path) && str_starts_with($path, $prefix)) {
                $paths[] = $path;
            }
        }
        if (count($paths) > SkillBundle::MAX_FILES) {
            throw new InvalidArgumentException(sprintf(t('skill.bundle.too_many'), SkillBundle::MAX_FILES));
        }
        $files = [];
        foreach ($paths as $path) {
            $files[substr($path, strlen($prefix))] = $this->body(self::RAW . $repo . '/' . $sha . '/' . implode('/', array_map('rawurlencode', explode('/', $path))), $cancellation);
        }
        return SkillBundle::fromFiles($files, sprintf('https://github.com/%s/tree/%s%s', $repo, $sha, $dir === '' ? '' : '/' . $dir), $sha);
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

    private function body(string $url, Cancellation $cancellation, string $accept = '*/*', int $limit = SkillBundle::MAX_FILE_BYTES + 1): string {
        $request = new Request($url);
        $request->setHeader('Accept', $accept);
        $request->setHeader('User-Agent', 'mercurius-agent');
        // the listing of a large repository is big; a file over the limit is refused by the bundle
        $request->setBodySizeLimit($limit);
        $response = $this->http->request($request, $cancellation);
        $body = $response->getBody()->buffer($cancellation);
        if ($response->getStatus() !== 200) {
            throw new InvalidArgumentException(sprintf(t('skill.import.http_failed'), $response->getStatus(), $url));
        }
        return $body;
    }
}
