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
 *      L285-L314 (_shell_and_script), L335-L379 (validate_mcp_server_entry) — the two shell rules and the peeling of
 *      wrappers are taken; the indicators of one campaign are not
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
     * The script given to a shell, after wrappers and their options are peeled; null — no shell.
     *
     * @param list<string> $argv
     */
    private function script(array $argv): ?string {
        $i = 0;
        $count = count($argv);
        while ($i < $count) {
            $base = strtolower(basename(str_replace('\\', '/', $argv[$i])));
            if (in_array($base, self::SHELLS, true)) {
                return implode(' ', array_slice($argv, $i + 1));
            }
            if (!in_array($base, self::WRAPPERS, true)) {
                return null;
            }
            $i++;
            // the options of a wrapper, their operands and NAME=value assignments
            while ($i < $count) {
                $token = $argv[$i];
                if ($token === '-u' || $token === '--unset' || $token === '-n' || $token === '-k') {
                    // an option with its operand: env -u NAME, nice -n 10, timeout -k 5
                    $i += 2;
                    continue;
                }
                if (str_starts_with($token, '-') || preg_match('~^[A-Za-z_][A-Za-z0-9_]*=~', $token) === 1 || preg_match('~^\d+(?:\.\d+)?[smhd]?$~', $token) === 1) {
                    $i++;
                    continue;
                }
                break;
            }
        }
        return null;
    }
}
