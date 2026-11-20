<?php

declare(strict_types=1);

/**
 * Keeps the specification and the code tied together. It reads the files git tracks in the main repository and in its
 * submodules, except the reference repositories.
 *
 *   php bin/spec-links.php                  — checks every "spec: <file>#<anchor>" and every link between the
 *                                             specification's markdown files; exit code 1 when one leads nowhere
 *   php bin/spec-links.php --where <anchor> — the way back: the places that refer to a section, by its anchor or by
 *                                             <file>#<anchor>; with a file alone — to any section of that file
 *   php bin/spec-links.php --coarse [chars] — the sections that are referred to and are larger than the limit
 *                                             (8000 characters with their subsections): candidates to split or to
 *                                             refer to more precisely
 *
 * spec: brief/architecture.md#соглашения — a spec reference leads to an existing heading, its path from the main repository
 */

$root = dirname(__DIR__);
const COARSE = 8000;

/** The anchor GitHub gives a heading: lower case, no punctuation, spaces as hyphens; a repeat gets -1, -2… */
function anchors(string $markdown): array {
    return array_fill_keys(array_keys(sections($markdown)), true);
}

/**
 * The sections of a markdown file by their anchors.
 *
 * @return array<string, array{level: int, line: int, title: string, size: int}> size — characters with the subsections
 */
function sections(string $markdown): array {
    $seen = [];
    $found = [];
    $fenced = false;
    $lines = preg_split('~\R~u', $markdown) ?: [];
    foreach ($lines as $number => $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $fenced = !$fenced;
            continue;
        }
        if ($fenced || preg_match('~^(#{1,6})\s+(.+?)\s*#*\s*$~u', $line, $m) !== 1) {
            continue;
        }
        $text = preg_replace('~`([^`]*)`~u', '$1', $m[2]) ?? $m[2];
        $text = preg_replace('~\[([^\]]*)\]\([^)]*\)~u', '$1', $text) ?? $text;
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('~[^\p{L}\p{N}\p{Mn}\- _]~u', '', $slug) ?? '';
        $slug = str_replace(' ', '-', $slug);
        $n = $seen[$slug] ?? 0;
        $seen[$slug] = $n + 1;
        if ($n > 0) {
            $slug .= '-' . $n;
        }
        $found[] = ['slug' => $slug, 'level' => strlen($m[1]), 'line' => $number, 'title' => $m[2]];
    }
    $out = [];
    foreach ($found as $i => $section) {
        $end = count($lines);
        for ($j = $i + 1; $j < count($found); $j++) {
            if ($found[$j]['level'] <= $section['level']) {
                $end = $found[$j]['line'];
                break;
            }
        }
        $size = 0;
        for ($k = $section['line'] + 1; $k < $end; $k++) {
            $size += mb_strlen($lines[$k]) + 1;
        }
        $out[$section['slug']] = ['level' => $section['level'], 'line' => $section['line'] + 1, 'title' => $section['title'], 'size' => $size];
    }
    return $out;
}

/** @return list<string> paths tracked by git under $dir, relative to $dir */
function tracked(string $dir): array {
    $list = shell_exec('git -C ' . escapeshellarg($dir) . ' ls-files -z 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    return is_string($list) ? array_values(array_filter(explode("\0", $list), static fn(string $p): bool => $p !== '')) : [];
}

/** A path with its "." and ".." parts resolved; null when it leaves the root. */
function normalize(string $path): ?string {
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

/** A markdown file whose links to other files are checked: the specification, the rules and the files of the repositories. */
function linked(string $path): bool {
    return preg_match('~\.md$~', $path) === 1 && preg_match('~^(docs|notes|references|plans|graphify-out|\.claude)/|(^|/)(SKILL\.md$|references/)~', $path) !== 1;
}

$mode = $argv[1] ?? '';
$argument = $argv[2] ?? '';

$repos = [''];
/** @var list<string> $absent submodules not checked out here: a link into one is not judged */
$absent = ['references/'];
$modules = shell_exec('git -C ' . escapeshellarg($root) . ' config --file .gitmodules --get-regexp path');
foreach (preg_split('~\R~u', is_string($modules) ? $modules : '') ?: [] as $line) {
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
$unjudged = static function (string $target) use ($absent): bool {
    foreach ($absent as $module) {
        if (str_starts_with($target, $module)) {
            return true;
        }
    }
    return false;
};

/** @var array<string, array<string, array{level: int, line: int, title: string, size: int}>|null> $cache */
$cache = [];
$sectionsOf = static function (string $target) use (&$cache, $root): ?array {
    if (!array_key_exists($target, $cache)) {
        $markdown = is_file($root . '/' . $target) ? @file_get_contents($root . '/' . $target) : false;
        $cache[$target] = is_string($markdown) ? sections($markdown) : null;
    }
    return $cache[$target];
};

$broken = [];
$count = 0;
$links = 0;
/** @var array<string, list<string>> $uses "file#anchor" => places */
$uses = [];
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
        if (!is_string($text)) {
            continue;
        }
        $markdown = linked($path);
        if (!$markdown && !str_contains($text, 'spec:')) {
            continue;
        }
        $fenced = false;
        foreach (preg_split('~\R~u', $text) ?: [] as $number => $line) {
            if (preg_match_all('~spec:\s+([\w./<>-]+\.md)#([^\s`\'")\],;]+)~u', $line, $all, PREG_SET_ORDER) !== 0) {
                foreach ($all as [, $target, $anchor]) {
                    if (str_contains($target, '<') || $anchor === '…' || $unjudged($target)) {
                        continue; // a pattern in the rules, not a link
                    }
                    $count++;
                    $anchor = mb_strtolower(rtrim($anchor, '.:'));
                    $known = $sectionsOf($target);
                    if ($known === null) {
                        $broken[] = sprintf('%s:%d: no file %s', $path, $number + 1, $target);
                    } elseif (!isset($known[$anchor])) {
                        $broken[] = sprintf('%s:%d: no heading %s#%s', $path, $number + 1, $target, $anchor);
                    } else {
                        $uses[$target . '#' . $anchor][] = $path . ':' . ($number + 1);
                    }
                }
            }
            if (!$markdown) {
                continue;
            }
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $fenced = !$fenced;
                continue;
            }
            if ($fenced) {
                continue;
            }
            $plain = preg_replace('~`[^`]*`~u', '', $line) ?? $line;
            if (preg_match_all('~\]\(([^)\s]+)\)~u', $plain, $all, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($all as [, $url]) {
                if (preg_match('~^([a-z][a-z0-9+.-]*:|//|<)~i', $url) === 1) {
                    continue;
                }
                [$to, $anchor] = array_pad(explode('#', $url, 2), 2, '');
                $target = $to === '' ? $path : normalize(dirname($path) . '/' . rawurldecode($to));
                if ($target === null || $unjudged($target . '/') || $unjudged($target)) {
                    continue;
                }
                $links++;
                if (!file_exists($root . '/' . $target)) {
                    $broken[] = sprintf('%s:%d: no file %s (link %s)', $path, $number + 1, $target, $url);
                    continue;
                }
                if ($anchor === '' || preg_match('~\.md$~', $target) !== 1) {
                    continue;
                }
                $known = $sectionsOf($target) ?? [];
                if (!isset($known[mb_strtolower(rawurldecode($anchor))])) {
                    $broken[] = sprintf('%s:%d: no heading %s#%s', $path, $number + 1, $target, $anchor);
                }
            }
        }
    }
}

if ($mode === '--where') {
    if ($argument === '') {
        fwrite(STDERR, "usage: php bin/spec-links.php --where <anchor | file#anchor | file>\n");
        exit(2);
    }
    $needle = mb_strtolower($argument);
    $found = 0;
    ksort($uses);
    foreach ($uses as $key => $places) {
        [$file, $anchor] = explode('#', $key, 2);
        if ($key !== $needle && $anchor !== ltrim($needle, '#') && $file !== $needle) {
            continue;
        }
        printf("%s (%d)\n", $key, count($places));
        foreach ($places as $place) {
            echo '  ', $place, "\n";
        }
        $found += count($places);
    }
    printf("%d places\n", $found);
    exit(0);
}

/** @var list<array{string, int, int}> $coarse */
$coarse = [];
$limit = $mode === '--coarse' && ctype_digit($argument) ? (int) $argument : COARSE;
foreach ($uses as $key => $places) {
    [$file, $anchor] = explode('#', $key, 2);
    $size = ($sectionsOf($file) ?? [])[$anchor]['size'] ?? 0;
    if ($size > $limit) {
        $coarse[] = [$key, $size, count($places)];
    }
}
if ($mode === '--coarse') {
    usort($coarse, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
    foreach ($coarse as [$key, $size, $n]) {
        printf("%4d links  %6d chars  %s\n", $n, $size, $key);
    }
    printf("%d links lead to %d sections over %d characters\n", array_sum(array_column($coarse, 2)), count($coarse), $limit);
    exit(0);
}

foreach ($broken as $line) {
    echo $line, "\n";
}
printf("spec links: %d checked, markdown links: %d checked, %d broken\n", $count, $links, count($broken));
printf("coarse: %d spec links lead to %d sections over %d characters (--coarse lists them)\n", array_sum(array_column($coarse, 2)), count($coarse), COARSE);
exit($broken === [] ? 0 : 1);
