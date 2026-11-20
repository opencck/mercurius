<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Guard;

/**
 * Hides secret-looking values in what is shown to people (approval cards): you cannot approve what you cannot see,
 * but the card must not leak a token into the chat history.
 *
 * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — secrets in the card are masked
 * ref: references/hermes-agent/tools/approval.py@e96221fa:L1421-L1479 — hermes shows the command in full; we mask values
 */
final class SecretMasker {
    /** shorter values are too much like ordinary words to be replaced everywhere */
    private const KNOWN_MIN = 8;
    /** the newest known values are kept; the oldest go first */
    private const KNOWN_MAX = 256;
    /** @var array<string, string> exact values of secrets the agent holds (the vault's, the tokens of its settings), by themselves */
    private static array $known = [];

    /**
     * An exact secret value: from now on it is masked wherever it shows up, whatever it looks like. Memory only.
     *
     * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — masks by the value of a secret, not only by its shape
     * ref: references/hermes-agent/agent/redact_vault_values.py@e96221fa:L13-L40 — a bounded registry of exact values;
     *      hermes keeps one per profile, here one per process
     */
    public static function remember(string $value): void {
        $value = trim($value);
        if (strlen($value) < self::KNOWN_MIN || isset(self::$known[$value])) {
            return;
        }
        self::$known[$value] = $value;
        if (count(self::$known) > self::KNOWN_MAX) {
            unset(self::$known[array_key_first(self::$known)]);
        }
    }

    /**
     * Text for another agent: secrets masked, and the personal data of people too — e-mail addresses and phone
     * numbers. The other agent works for someone else.
     *
     * spec: brief/network.md#подпись-адреса-доказательство-ключа-и-маскирование — what goes to another agent carries no addresses and phones
     * ref: references/hermes-agent/agent/redact.py@e96221fa:L757-L759 — E.164 without a word or dot glued before the
     *      plus (a version like 3.14.7+20260901 stays); here also the spaced Russian form, +7 or 8 with brackets
     */
    public static function forAgents(string $text): string {
        $text = self::mask($text);
        $text = preg_replace('~[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}~', '[email]', $text) ?? $text;
        $text = preg_replace('~(?<![0-9A-Za-z_.])\+[1-9]\d{6,14}(?![A-Za-z0-9])~', '[phone]', $text) ?? $text;
        return preg_replace('~(?<![\w+.])(?:\+7|8)[\s(-]*\d{3}[\s)-]*\d{3}[\s-]?\d{2}[\s-]?\d{2}(?!\d)~u', '[phone]', $text) ?? $text;
    }

    /** Names whose values are secrets: password, token, api_key, X-Api-Key, Authorization, client_secret…; an author is not an auth */
    private const SECRET_NAME = '(?:[\w-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|auth(?!or(?!i[sz]))|cookie|session)[\w-]*)';

    /**
     * Whether a text carries a secret by its name or its shape: a long hex or base64 string alone is not taken as one
     * (a hash of a commit, a picture) — the mask of output still hides those.
     *
     * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a message is deleted for a secret, not for a hash
     */
    public static function carriesSecret(string $text): bool {
        foreach (self::patterns(false) as $pattern => $replacement) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        foreach (self::$known as $value) {
            if (str_contains($text, $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param bool $bare with the rules for a long hex or base64 string with no name around it
     * @return array<non-empty-string, string>
     */
    private static function patterns(bool $bare = true): array {
        $name = self::SECRET_NAME;
        return [
            // URL credentials: https://user:password@host, and a long id as the user (vless://<uuid>@host)
            '#(://[^/\s:@]+:)[^/\s@]+@#' => '$1***@',
            '#(://)[A-Za-z0-9._~%-]{16,}@#' => '$1***@',
            // command-line passwords: mysql -phunter2, --password secret (a port like -p22 or -p 22 stays)
            '/(\s-p)(?=[^\s]*[A-Za-z])[^\s]{4,}/' => '$1***',
            '/(--password[\s=]+)\S+/i' => '$1***',
            // JSON: "password": "…", "X-Api-Key":"…"
            '/("' . $name . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"***"',
            '/(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{6,}/i' => '$1 ***',
            // key=value, key: value, ?access_key=…
            '/(\b' . $name . '\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&"\']+)/i' => '$1***',
            // In Russian: «пароль от роутера: …», «токен …»: the value after a colon, or a word of letters and digits
            // within a few words — the eval caught a password said in Russian landing in a memory fact.
            // «токенов: 18318» is a count, not a secret; the word itself ends before a colon, so the value masked is the
            // one after the nearest colon, not after a later one («токенов: 18318, время: 61 с»).
            '/((?:парол|токен(?!ов(?![\p{L}\d]))|ключ\s+api|секретн\S*\s+ключ|pin|пин-?код)[^\s:=]*[^\n:=]{0,40}?[:=]\s*)("[^"]*"|\S+)/iu' => '$1***',
            '/((?:парол|токен(?!ов(?![\p{L}\d]))|пин-?код)\S*(?:\s+[^\s]+){0,4}?\s+)(?=\S*\d)(?=\S*[A-Za-z])[^\s,;]{6,}/iu' => '$1***',
            // ref: references/hermes-agent/agent/redact.py@e96221fa:L620-L640 — a bot token and a PEM private key
            '/(?<![A-Za-z_\d])(bot)?(\d{8,}):[-A-Za-z0-9_]{30,}/' => '$1$2:***',
            '/-----BEGIN[A-Z ]*PRIVATE KEY-----[\s\S]*?-----END[A-Z ]*PRIVATE KEY-----/' => '[PRIVATE KEY]',
            '/\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}/' => '$1-***',
            '/\b(ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{16,}/' => '$1_***',
            '/\bxox[abprs]-[A-Za-z0-9-]{10,}/' => 'xox***',
            '/\bAKIA[0-9A-Z]{16}\b/' => 'AKIA***',
        ] + ($bare ? [
            '/\b[0-9a-f]{32,}\b/i' => '***',
            '/\b[A-Za-z0-9+\/]{40,}={0,2}/' => '***',
        ] : []);
    }

    public static function mask(string $text): string {
        if (self::$known !== []) {
            $values = array_values(self::$known);
            // the longest first: a value inside another is not left half-shown
            usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            $text = str_replace($values, '***', $text);
        }
        foreach (self::patterns() as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        return $text;
    }

    /**
     * Masks tool arguments before they are shown: values under secret names are hidden whole, other strings are
     * masked as text. Masking the decoded values, not the encoded JSON, catches "X-Api-Key": "…" and the like.
     *
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    public static function maskArgs(array $args): array {
        $keys = array_keys($args);
        return array_combine($keys, array_map(self::maskValue(...), $keys, array_values($args)));
    }

    private static function maskValue(int|string $key, mixed $value): mixed {
        if (is_string($key) && preg_match('/^' . self::SECRET_NAME . '$/i', $key) === 1 && $value !== null && $value !== '') {
            return '***';
        }
        if (is_array($value)) {
            return self::maskArgs($value);
        }
        return is_string($value) ? self::mask($value) : $value;
    }
}
