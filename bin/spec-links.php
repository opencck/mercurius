<?php

declare(strict_types=1);

/**
 * Checks that every "spec: <file>#<anchor>" in the project leads somewhere: the file exists (its path from the root of
 * the main repository) and has a heading with that anchor. It reads the files git tracks in the main repository and in
 * its submodules, except the reference repositories.
 *
 *   php bin/spec-links.php        — prints every broken link; exit code 1 when there is one
 *
 * spec: brief/architecture.md#соглашения — a spec reference leads to an existing heading, its path from the main repository
 */

$root = dirname(__DIR__);

/** The anchor GitHub gives a heading: lower case, no punctuation, spaces as hyphens; a repeat gets -1, -2… */
function anchors(string $markdown): array {
    $seen = [];
    $fenced = false;
    foreach (preg_split('~\R~', $markdown) ?: [] as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $fenced = !$fenced;
            continue;
        }
        if ($fenced || preg_match('~^#{1,6}\s+(.+?)\s*#*\s*$~u', $line, $m) !== 1) {
            continue;
        }
        $text = preg_replace('~`([^`]*)`~u', '$1', $m[1]) ?? $m[1];
        $text = preg_replace('~\[([^\]]*)\]\([^)]*\)~u', '$1', $text) ?? $text;
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('~[^\p{L}\p{N}\p{Mn}\- _]~u', '', $slug) ?? '';
        $slug = str_replace(' ', '-', $slug);
        $base = $slug;
        $n = $seen[$base] ?? 0;
        $seen[$base] = $n + 1;
        if ($n > 0) {
            $slug = $base . '-' . $n;
            $seen[$slug] = ($seen[$slug] ?? 0) + 1;
        }
        $seen['#' . $slug] = 1;
    }
    $out = [];
    foreach (array_keys($seen) as $key) {
        if (str_starts_with((string) $key, '#')) {
            $out[substr((string) $key, 1)] = true;
        }
    }
    return $out;
}

/** @return list<string> paths tracked by git under $dir, relative to $dir */
function tracked(string $dir): array {
    $list = shell_exec('git -C ' . escapeshellarg($dir) . ' ls-files -z 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    return is_string($list) ? array_values(array_filter(explode("\0", $list), static fn(string $p): bool => $p !== '')) : [];
}

$repos = [''];
/** @var list<string> $absent submodules not checked out here: a link into one is not judged */
$absent = [];
$modules = shell_exec('git -C ' . escapeshellarg($root) . ' config --file .gitmodules --get-regexp path');
foreach (preg_split('~\R~', is_string($modules) ? $modules : '') ?: [] as $line) {
    $path = trim((string) preg_replace('~^\S+\s+~', '', $line));
    if ($path === '' || str_starts_with($path, 'references/')) {
        continue;
    }
    if (file_exists($root . '/' . $path . '/.git')) {
        $repos[] = $path;
    } else {
        $absent[] = $path . '/';
    }
}

$cache = [];
$broken = [];
$count = 0;
foreach ($repos as $repo) {
    $base = $repo === '' ? $root : $root . '/' . $repo;
    foreach (tracked($base) as $file) {
        $path = $repo === '' ? $file : $repo . '/' . $file;
        if (str_starts_with($path, 'references/') || preg_match('~\.(php|sql|js|mjs|ts|py|md|yml|yaml|sh|json|neon|xml)$|Dockerfile$~', $path) !== 1) {
            continue;
        }
        if (is_dir($root . '/' . $path) || preg_match('~\.(md)$~', $path) === 1 && str_starts_with($path, 'docs/')) {
            continue;
        }
        $text = @file_get_contents($root . '/' . $path);
        if (!is_string($text) || !str_contains($text, 'spec:')) {
            continue;
        }
        foreach (preg_split('~\R~', $text) ?: [] as $number => $line) {
            if (preg_match_all('~spec:\s+([\w./<>-]+\.md)#([^\s`\'")\],;]+)~u', $line, $all, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($all as [, $target, $anchor]) {
                if (str_contains($target, '<') || $anchor === '…') {
                    continue; // a pattern in the rules, not a link
                }
                foreach ($absent as $module) {
                    if (str_starts_with($target, $module)) {
                        continue 2;
                    }
                }
                $count++;
                $anchor = rtrim($anchor, '.:');
                if (!isset($cache[$target])) {
                    $markdown = @file_get_contents($root . '/' . $target);
                    $cache[$target] = is_string($markdown) ? anchors($markdown) : null;
                }
                if ($cache[$target] === null) {
                    $broken[] = sprintf('%s:%d: no file %s', $path, $number + 1, $target);
                } elseif (!isset($cache[$target][mb_strtolower($anchor)])) {
                    $broken[] = sprintf('%s:%d: no heading %s#%s', $path, $number + 1, $target, $anchor);
                }
            }
        }
    }
}

foreach ($broken as $line) {
    echo $line, "\n";
}
printf("spec links: %d checked, %d broken\n", $count, count($broken));
exit($broken === [] ? 0 : 1);
