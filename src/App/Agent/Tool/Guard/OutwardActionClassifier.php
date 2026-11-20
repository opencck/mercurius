<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Guard;

use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * Tells a sandbox command that acts outside (pushes, remote shells, uploads) from local work. Commands inside the
 * sandbox are writes without approval; outward actions are irreversible and need the owner. What cannot be parsed
 * with confidence — substitutions, eval, code in a string for an interpreter, heredocs, "download and run" — is
 * irreversible too and has no grant key, so it is asked about every time.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты — threshold, recognised actions, keys of saved grants
 * ref: references/hermes-agent/tools/approval_detection.py@e96221fa:L1603-L1619 (_command_detection_variants) —
 *      normalise before matching (quotes, escapes, split words like pu""sh); hermes keeps a blacklist of dangerous
 *      patterns, we keep a short list of outward actions and refuse to guess about the rest
 */
final class OutwardActionClassifier {
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

    /** Programs that run another program given in their arguments. */
    private const WRAPPERS = ['timeout', 'nice', 'time', 'stdbuf', 'ionice', 'setsid', 'xargs', 'watch', 'flock', 'parallel',
        'chronic', 'strace', 'ltrace', 'script', 'unbuffer', 'taskset', 'chroot', 'doas', 'su', 'runuser', 'busybox', 'find'];
    private const OUTWARD = ['git', 'ssh', 'sftp', 'scp', 'rsync', 'curl', 'wget'];

    /**
     * A grant key is given only to a single plain command with one outward action: in a chain a grant could cover a
     * second action, or a local step could change where the first one goes (a remote URL, insteadOf, a proxy).
     */
    public function classify(string $command): Classification {
        foreach (self::OPAQUE as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return new Classification(DangerClass::Irreversible, null, t('tool.command_classifier.komanda_kotoruyu_nelzya_razobrat_s'));
            }
        }
        // Redirections such as 2>&1 and &>file are not separators.
        $plain = preg_replace('/\d*>&\d*-?|&>>?|<&\d*/', ' > ', $command) ?? $command;
        $findings = [];
        $commands = 0;
        foreach (preg_split('/&&|\|\||;|\||\n|&/', $plain) ?: [] as $segment) {
            $tokens = self::tokens($segment);
            if ($tokens === []) {
                continue;
            }
            $commands++;
            $found = $this->segment($tokens);
            if ($found !== null) {
                $findings[] = $found;
            }
        }
        if ($findings === []) {
            return new Classification(DangerClass::Write, null, t('tool.command_classifier.komanda_v_pesochnitse'));
        }
        if ($commands === 1 && count($findings) === 1) {
            return $findings[0];
        }
        return new Classification(DangerClass::Irreversible, null, implode('; ', array_map(static fn(Classification $c): string => $c->label, $findings)));
    }

    /** @param list<string> $tokens */
    private function segment(array $tokens): ?Classification {
        // Groups and subshells: "( git push )", "(git push)", "{ git push; }".
        $tokens = array_values(array_filter(
            array_map(static fn(string $t): string => trim($t, '(){}'), $tokens),
            static fn(string $t): bool => $t !== '',
        ));
        $prefixed = false;
        while ($tokens !== [] && (preg_match('/^\w+=/', $tokens[0]) === 1 || in_array($tokens[0], ['sudo', 'nohup', 'exec', 'command', 'env'], true))) {
            // Variables and env change how the program behaves (proxy, ssh command, git config): no key.
            $prefixed = $prefixed || preg_match('/^\w+=/', $tokens[0]) === 1 || $tokens[0] === 'env';
            array_shift($tokens);
        }
        if ($tokens === []) {
            return null;
        }
        $program = basename(array_shift($tokens));
        if (in_array($program, self::WRAPPERS, true)) {
            return self::wraps($tokens) ? new Classification(DangerClass::Irreversible, null, $program . t('tool.command_classifier.zapuskaet_vneshnee_deystvie')) : null;
        }
        $found = match ($program) {
            'git' => $this->git($tokens),
            'ssh', 'sftp' => $this->remoteShell($program, $tokens),
            'scp', 'rsync' => $this->copy($program, $tokens),
            'curl', 'wget' => $this->upload($program, $tokens),
            default => null,
        };
        return $found !== null && $prefixed ? new Classification($found->danger, null, $found->label) : $found;
    }

    /** @param list<string> $tokens */
    private static function wraps(array $tokens): bool {
        foreach ($tokens as $token) {
            if (in_array(basename($token), self::OUTWARD, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $tokens */
    private function git(array $tokens): ?Classification {
        $args = self::positional($tokens, ['-C', '-c', '--git-dir', '--work-tree', '--namespace', '--exec-path', '--config-env']);
        if (($args[0] ?? null) !== 'push') {
            return null;
        }
        $optionsBefore = ($tokens[0] ?? '') !== 'push';
        $remote = $args[1] ?? null;
        $url = $remote === null ? null : self::urlTarget($remote);
        $label = t('tool.command_classifier.git_push_v') . ($remote ?? t('tool.command_classifier.udalennyy_repozitoriy_po_umolchaniyu'));
        // A remote name can be pointed anywhere by a local write (git remote set-url), so only an address has a key.
        return new Classification(DangerClass::Irreversible, $url === null || $optionsBefore ? null : 'git-push:' . $url, $label);
    }

    /** @param list<string> $tokens */
    private function remoteShell(string $program, array $tokens): ?Classification {
        $args = self::positional($tokens, ['-p', '-P', '-i', '-l', '-o', '-F', '-J', '-L', '-R', '-D', '-b', '-c', '-E', '-S', '-W', '-w']);
        $host = isset($args[0]) ? self::host($args[0]) : null;
        return $host === null ? null : new Classification(DangerClass::Irreversible, $program . ':' . $host, $program . t('tool.command_classifier.na') . $host);
    }

    /** @param list<string> $tokens */
    private function copy(string $program, array $tokens): ?Classification {
        foreach ($tokens as $token) {
            if (str_starts_with($token, '-') || preg_match('#^[^/:]+:#', $token) !== 1) {
                continue;
            }
            $host = self::host(substr($token, 0, (int) strpos($token, ':')));
            if ($host !== null) {
                return new Classification(DangerClass::Irreversible, $program . ':' . $host, $program . t('tool.command_classifier.na') . $host);
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
        return new Classification(DangerClass::Irreversible, 'upload:' . $host, $program . t('tool.command_classifier.otpravlyaet_dannye_na') . $host);
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
