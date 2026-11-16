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
    private const PATTERNS = [
        '/(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{6,}/i' => '$1 ***',
        '/((?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|auth)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&"\']+)/i' => '$1***',
        '/\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}/' => '$1-***',
        '/\b(ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{16,}/' => '$1_***',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/' => 'xox***',
        '/\bAKIA[0-9A-Z]{16}\b/' => 'AKIA***',
        '/\b[0-9a-f]{32,}\b/i' => '***',
        '/\b[A-Za-z0-9+\/]{40,}={0,2}/' => '***',
    ];

    public static function mask(string $text): string {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        return $text;
    }
}
