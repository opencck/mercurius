<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use OpenCCK\App\Agent\Tool\Guard\SecretMasker;

/**
 * A static check of a skill from outside before it reaches the owner's card: what its text and scripts would do if the
 * agent followed them. A critical finding blocks the import; the rest are shown on the card for the owner to weigh.
 *
 * spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — a skill from outside is checked before the card
 * ref: references/hermes-agent/tools/skills_guard.py@e96221fa:L172-L560 (THREAT_PATTERNS) — the main categories:
 *      secrets sent out, credential stores read, the environment dumped, reverse shells, persistence, destruction,
 *      instructions planted in the text, invisible characters; hermes has some 400 patterns, these are the ones
 *      that matter for a skill run in our sandbox
 */
final class SkillGuard {
    /** @var list<array{string, string, string, string}> regex, id, severity, description */
    private const PATTERNS = [
        // secrets sent out
        ['/\b(?:curl|wget|fetch|requests\.\w+|httpx\.\w+|Invoke-WebRequest)\b[^\n]*\$\{?\w*(?:KEY|TOKEN|SECRET|PASSWORD|CREDENTIAL)S?\b/i', 'env_exfil', 'critical', 'sends a secret from the environment over the network'],
        ['/\b(?:dig|nslookup)\s+[^\n]*\$(?:\{[^}]*\}|\(|\w)[^\n]*\.[a-z]{2,}/i', 'dns_exfil', 'critical', 'puts data into a DNS name'],
        ['/!\[[^\]]*\]\(https?:\/\/[^)]*\$\{?/i', 'md_image_exfil', 'high', 'markdown image URL built from a variable'],
        // credential stores
        ['/(?:\$HOME|~|expanduser\([\'"]~)\/\.(?:ssh|aws|gnupg|kube|docker|netrc|pgpass|npmrc|pypirc)/i', 'ssh_dir_access', 'critical', 'reads a credential store of the user'],
        ['/\bcat\s+(?!>)[^\n|;&]*(?:\.env\b|credentials|\.netrc|\.pgpass|id_rsa|id_ed25519)/i', 'read_secrets_file', 'critical', 'reads a known secrets file'],
        ['/printenv|(?<![\w-])env\s*\|(?!\|)|os\.environ\b(?!\s*\.get\b)|process\.env\b(?!\.\w)/', 'dump_all_env', 'high', 'reads the whole environment'],
        // remote control
        ['/\b(?:nc|ncat|netcat)\b[^\n]*\s-[a-z]*e\s|\/dev\/tcp\/|bash\s+-i\s+>&|socket\.socket\([^\n]*\bconnect\b/i', 'reverse_shell', 'critical', 'opens a remote shell'],
        ['/\b(?:curl|wget)\b[^\n|]*\|\s*(?:sudo\s+)?(?:ba|z)?sh\b/i', 'pipe_to_shell', 'high', 'runs a script downloaded from the network'],
        // persistence and the agent's own setup
        ['/\bcrontab\b|\/etc\/cron|systemctl\s+(?:enable|start)|\.(?:bashrc|zshrc|profile|bash_profile)\b|authorized_keys/i', 'persistence', 'high', 'installs itself to run again or to let someone in'],
        ['/\b(?:sudo|chmod\s+[0-7]*7[0-7]*\s+\/|chown\s+root)\b/', 'privilege', 'medium', 'asks for more rights than a sandbox gives'],
        // destruction
        ['/\brm\s+-[a-z]*r[a-z]*f?[a-z]*\s+(?:\/(?:\s|$|\*)|~\/?(?:\s|$)|\$HOME\/?(?:\s|$))|\bmkfs\b|\bdd\s+if=\S+\s+of=\/dev\/[sh]d|:\(\)\s*\{\s*:\|:&\s*\};:/i', 'destructive', 'critical', 'destroys files or a disk'],
        // instructions planted in the text
        ['/\b(?:ignore|disregard|forget)\s+(?:all\s+|any\s+|the\s+)?(?:previous|prior|above|earlier|your)\s+(?:instructions|rules|prompts?|directions)/i', 'prompt_injection', 'critical', 'tells the agent to drop its instructions'],
        ['/(?:игнорируй|забудь|отмени)\s+(?:все\s+|всё\s+)?(?:предыдущие|прежние|свои|системные)\s+(?:инструкци|правил|указани)/iu', 'prompt_injection', 'critical', 'tells the agent to drop its instructions'],
        ['/\b(?:reveal|print|show|send|output)\s+(?:me\s+)?(?:the\s+|your\s+)?(?:system\s+prompt|hidden\s+instructions|api\s+keys?|secrets?)\b/i', 'reveal_secrets', 'high', 'asks the agent to give away its prompt or secrets'],
        ['/\b(?:do\s+not|don\'t|never)\s+(?:tell|inform|ask|notify)\s+(?:the\s+)?(?:user|owner|human)\b/i', 'hide_from_owner', 'high', 'asks the agent to hide something from the owner'],
        ['/\b(?:you\s+are\s+now|act\s+as\s+if\s+you\s+have\s+no)\b[^\n]*(?:restrictions|rules|limits|jailbr)/i', 'role_hijack', 'high', 'tries to change who the agent is'],
    ];

    private const HIDDEN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{E0000}-\x{E007F}]/u';
    private const SEVERITY = ['critical' => 0, 'high' => 1, 'medium' => 2];

    /** @return list<array{id: string, severity: string, file: string, line: int, description: string}> worst first */
    public static function scan(SkillBundle $bundle): array {
        $findings = [];
        foreach (['SKILL.md' => $bundle->description . "\n" . $bundle->body] + $bundle->files as $file => $text) {
            foreach (explode("\n", $text) as $i => $line) {
                foreach (self::PATTERNS as [$regex, $id, $severity, $description]) {
                    if (preg_match($regex, $line) === 1) {
                        $findings[] = ['id' => $id, 'severity' => $severity, 'file' => self::key($file), 'line' => $i + 1, 'description' => $description];
                    }
                }
                if (preg_match(self::HIDDEN, $line) === 1) {
                    $findings[] = ['id' => 'invisible_unicode', 'severity' => 'critical', 'file' => self::key($file), 'line' => $i + 1, 'description' => 'invisible characters that can hide text from the owner'];
                }
                // by a name or a shape: a long id alone (a path, a hash in a link) is not a finding, as in a chat message
                if (SecretMasker::carriesSecret($line)) {
                    $findings[] = ['id' => 'secret_in_file', 'severity' => 'high', 'file' => self::key($file), 'line' => $i + 1, 'description' => 'looks like a secret written into the skill'];
                }
            }
        }
        usort($findings, /**
         * @param array{id: string, severity: string, file: string, line: int, description: string} $a
         * @param array{id: string, severity: string, file: string, line: int, description: string} $b
         */ static fn(array $a, array $b): int => [self::rank($a['severity']), $a['file'], $a['line']] <=> [self::rank($b['severity']), $b['file'], $b['line']]);
        return $findings;
    }

    /** @param list<array{id: string, severity: string, file: string, line: int, description: string}> $findings */
    public static function blocks(array $findings): bool {
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'critical') {
                return true;
            }
        }
        return false;
    }

    private static function rank(string $severity): int {
        return self::SEVERITY[$severity] ?? 3;
    }

    /** An array key as text: PHP turns a file named "1" into an integer key. */
    private static function key(int|string $key): string {
        return (string) $key;
    }
}
