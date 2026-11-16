<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use OpenCCK\Domain\Agent\DangerClass;

/**
 * Tells a sandbox command that acts outside (pushes, remote shells, uploads) from local work. Commands inside the
 * sandbox are writes without approval; outward actions are irreversible and need the owner. What cannot be parsed
 * with confidence — substitutions, eval, code in a string for an interpreter, heredocs, "download and run" — is
 * irreversible too and has no grant key, so it is asked about every time.
 *
 * spec: BRIEF.md#подтверждения-и-запреты — threshold, recognised actions, keys of saved grants
 * ref: references/hermes-agent/tools/approval_detection.py@e96221f:L1603-L1619 (_command_detection_variants) —
 *      normalise before matching (quotes, escapes, split words like pu""sh); hermes keeps a blacklist of dangerous
 *      patterns, we keep a short list of outward actions and refuse to guess about the rest
 */
final class CommandClassifier {
    private const OPAQUE = [
        '/\$\(/',
        '/`/',
        '/<</',
        '/(^|[\s;&|(])eval(\s|$)/',
        '/(^|[\s;&|(])(bash|sh|zsh|dash|ksh)\s+(-\w*c\w*|--command)(\s|$)/',
        '/(^|[\s;&|(])(python3?|perl|ruby)\s+-\w*[ce]\w*(\s|$)/',
        '/(^|[\s;&|(])node\s+(-e|--eval|-p|--print)(\s|$)/',
        '/(^|[\s;&|(])php\s+-r(\s|$)/',
        '/\|\s*(sudo\s+)?(sh|bash|zsh|dash|python3?|perl|ruby|node|php)(\s|$)/',
    ];

    public function classify(string $command): Classification {
        foreach (self::OPAQUE as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return new Classification(DangerClass::Irreversible, null, 'команда, которую нельзя разобрать с уверенностью');
            }
        }
        foreach (preg_split('/&&|\|\||;|\||\n/', $command) ?: [] as $segment) {
            $found = $this->segment(self::tokens($segment));
            if ($found !== null) {
                return $found;
            }
        }
        return new Classification(DangerClass::Write, null, 'команда в песочнице');
    }

    /** @param list<string> $tokens */
    private function segment(array $tokens): ?Classification {
        while ($tokens !== [] && (preg_match('/^\w+=/', $tokens[0]) === 1 || in_array($tokens[0], ['sudo', 'nohup', 'exec', 'command', 'env'], true))) {
            array_shift($tokens);
        }
        if ($tokens === []) {
            return null;
        }
        $program = basename(array_shift($tokens));
        return match ($program) {
            'git' => $this->git($tokens),
            'ssh', 'sftp' => $this->remoteShell($program, $tokens),
            'scp', 'rsync' => $this->copy($program, $tokens),
            'curl', 'wget' => $this->upload($program, $tokens),
            default => null,
        };
    }

    /** @param list<string> $tokens */
    private function git(array $tokens): ?Classification {
        $args = self::positional($tokens, ['-C', '-c', '--git-dir', '--work-tree']);
        if (($args[0] ?? null) !== 'push') {
            return null;
        }
        $target = $args[1] ?? 'default';
        $target = self::urlTarget($target) ?? $target;
        return new Classification(DangerClass::Irreversible, 'git-push:' . $target, 'git push в ' . $target);
    }

    /** @param list<string> $tokens */
    private function remoteShell(string $program, array $tokens): ?Classification {
        $args = self::positional($tokens, ['-p', '-P', '-i', '-l', '-o', '-F', '-J', '-L', '-R', '-D', '-b', '-c', '-E', '-S', '-W', '-w']);
        $host = isset($args[0]) ? self::host($args[0]) : null;
        return $host === null ? null : new Classification(DangerClass::Irreversible, $program . ':' . $host, $program . ' на ' . $host);
    }

    /** @param list<string> $tokens */
    private function copy(string $program, array $tokens): ?Classification {
        foreach ($tokens as $token) {
            if (str_starts_with($token, '-') || preg_match('#^[^/:]+:#', $token) !== 1) {
                continue;
            }
            $host = self::host(substr($token, 0, (int) strpos($token, ':')));
            if ($host !== null) {
                return new Classification(DangerClass::Irreversible, $program . ':' . $host, $program . ' на ' . $host);
            }
        }
        return null;
    }

    /** @param list<string> $tokens */
    private function upload(string $program, array $tokens): ?Classification {
        $sends = false;
        $host = null;
        foreach ($tokens as $index => $token) {
            $next = strtoupper($tokens[$index + 1] ?? '');
            if (preg_match('/^(-d|--data.*|-F|--form.*|-T|--upload-file|--post-data.*|--post-file.*|--body-data.*|--body-file.*)$/', $token) === 1
                || preg_match('/^-d.+/', $token) === 1
                || (in_array($token, ['-X', '--request', '--method'], true) && in_array($next, ['POST', 'PUT', 'PATCH', 'DELETE'], true))
                || preg_match('/^(-X|--request=|--method=)(POST|PUT|PATCH|DELETE)$/i', $token) === 1) {
                $sends = true;
            }
            if ($host === null && preg_match('#^https?://([^/:?\s]+)#i', $token, $match) === 1) {
                $host = strtolower($match[1]);
            }
        }
        if (!$sends) {
            return null;
        }
        $host ??= 'unknown';
        return new Classification(DangerClass::Irreversible, 'upload:' . $host, $program . ' отправляет данные на ' . $host);
    }

    /**
     * Arguments that are not options; the named options take a value that is skipped too.
     *
     * @param list<string> $tokens
     * @param list<string> $withValue
     * @return list<string>
     */
    private static function positional(array $tokens, array $withValue): array {
        $result = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (in_array($tokens[$i], $withValue, true)) {
                $i++;
                continue;
            }
            if (!str_starts_with($tokens[$i], '-')) {
                $result[] = $tokens[$i];
            }
        }
        return $result;
    }

    private static function host(string $target): ?string {
        $host = strtolower(preg_replace('/^.*@/', '', $target) ?? '');
        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : null;
    }

    /** host/path of a URL or an scp-like git remote, null for a remote name */
    private static function urlTarget(string $target): ?string {
        if (preg_match('#^[a-z+]+://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.+)$#i', $target, $match) === 1) {
            return strtolower($match[1]) . '/' . $match[2];
        }
        if (preg_match('#^[^@/\s]+@([^:/\s]+):(.+)$#', $target, $match) === 1) {
            return strtolower($match[1]) . '/' . $match[2];
        }
        return null;
    }

    /**
     * Words of a shell segment with quotes and escapes removed: "git pu""sh" and git\ push read as the plain words.
     *
     * @return list<string>
     */
    private static function tokens(string $segment): array {
        preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/', trim($segment), $matches);
        return array_values(array_filter(array_map(
            static fn(string $word): string => str_replace(['"', "'", '\\'], '', $word),
            $matches[0],
        ), static fn(string $word): bool => $word !== ''));
    }
}
