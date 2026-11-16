<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * Hides secret-looking values in what is shown to people (approval cards): you cannot approve what you cannot see,
 * but the card must not leak a token into the chat history.
 *
 * spec: BRIEF.md#подтверждения-и-запреты — secrets in the card are masked
 * ref: references/hermes-agent/tools/approval.py@e96221f:L1421-L1479 — hermes shows the command in full; we mask values
 */
final class SecretMasker {
    /** Names whose values are secrets: password, token, api_key, X-Api-Key, Authorization, client_secret… */
    private const SECRET_NAME = '(?:[\w-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|auth|authorization|cookie|session)[\w-]*)';

    /** @return array<non-empty-string, string> */
    private static function patterns(): array {
        $name = self::SECRET_NAME;
        return [
            // URL credentials: https://user:password@host
            '#(://[^/\s:@]+:)[^/\s@]+@#' => '$1***@',
            // JSON: "password": "…", "X-Api-Key":"…"
            '/("' . $name . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"***"',
            '/(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{6,}/i' => '$1 ***',
            // key=value, key: value, ?access_key=…
            '/(\b' . $name . '\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&"\']+)/i' => '$1***',
            '/\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}/' => '$1-***',
            '/\b(ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{16,}/' => '$1_***',
            '/\bxox[abprs]-[A-Za-z0-9-]{10,}/' => 'xox***',
            '/\bAKIA[0-9A-Z]{16}\b/' => 'AKIA***',
            '/\b[0-9a-f]{32,}\b/i' => '***',
            '/\b[A-Za-z0-9+\/]{40,}={0,2}/' => '***',
        ];
    }

    public static function mask(string $text): string {
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
