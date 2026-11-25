<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use function Mercurius\I18n\t;

/**
 * A cheap sieve of an entry before it is approved and before it runs: a shell that reaches the network, or a shell
 * that writes where a backdoor is kept. Not a whitelist — npx, uvx, scripts stay legal; the border is the approval
 * by hash and the sandbox.
 *
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the check of an entry, when it is saved and when it runs
 * ref: references/hermes-agent/hermes_cli/mcp_security.py@e96221fa:L15-L45 (patterns), L59-L80 (wrappers),
 *      L285-L314 (_shell_and_script), L335-L379 (validate_mcp_server_entry) — the two shell rules and the wrappers are
 *      taken; instead of hermes' table of wrapper options a shell is looked for in every word after a wrapper, which
 *      no option can hide; the indicators of one campaign are not
 */
final class McpEntryGuard {
    private const SHELLS = ['bash', 'sh', 'zsh', 'dash', 'fish', 'ash', 'ksh', 'cmd', 'cmd.exe', 'powershell', 'powershell.exe', 'pwsh', 'pwsh.exe'];
    private const WRAPPERS = ['env', 'sudo', 'doas', 'nice', 'ionice', 'stdbuf', 'timeout', 'timelimit', 'nohup', 'setsid', 'time', 'command', 'builtin', 'busybox', 'eatmydata', 'catchsegv', 'taskset', 'chrt', 'strace', 'softlimit'];
    private const EGRESS = '~(?<![\w.-])(?:curl|wget|nc|ncat|socat)(?![\w.-])|/dev/tcp/|\bInvoke-WebRequest\b|\bInvoke-RestMethod\b|\bSystem\.Net\.WebClient\b~i';
    private const PERSISTENCE = '~authorized_keys|\.ssh/|/etc/ssh\b|/etc/pam\.d\b|pam_[\w-]+\.so|/etc/sudoers|/etc/cron|crontab\b|/etc/rc\.local|/etc/systemd|\.bashrc\b|\.bash_profile\b|\.profile\b|\.zshrc\b~i';

    /** Why the entry must not run; null — nothing found. */
    public function problem(McpEntry $entry): ?string {
        if ($entry->command === null) {
            return null;
        }
        $script = $this->script([$entry->command, ...$entry->args]);
        if ($script === null) {
            return null;
        }
        if (preg_match(self::EGRESS, $script) === 1) {
            return sprintf(t('mcp.guard.egress'), $entry->name);
        }
        if (preg_match(self::PERSISTENCE, $script) === 1) {
            return sprintf(t('mcp.guard.persistence'), $entry->name);
        }
        return null;
    }

    /**
     * The script given to a shell; null — no shell. Behind a wrapper the shell is looked for in every word of the rest:
     * the options of wrappers are many (env -S splits a string, timeout --signal takes an operand), and a word that
     * names a shell after a wrapper is a shell whatever stands before it.
     *
     * @param list<string> $argv
     */
    private function script(array $argv): ?string {
        $first = self::base($argv[0] ?? '');
        if (in_array($first, self::SHELLS, true)) {
            return implode(' ', array_slice($argv, 1));
        }
        if (!in_array($first, self::WRAPPERS, true)) {
            return null;
        }
        $words = preg_split('~[\s\'"]+~', implode(' ', array_slice($argv, 1)), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return null;
        }
        foreach ($words as $i => $word) {
            if (in_array(self::base($word), self::SHELLS, true)) {
                return implode(' ', array_slice($words, $i + 1));
            }
        }
        return null;
    }

    private static function base(string $word): string {
        return strtolower(basename(str_replace('\\', '/', $word)));
    }
}
