<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

/**
 * The mailbox of the agent from the settings, as headers for the built-in mail MCP server; null when it is not set up.
 *
 * spec: brief/skills-mcp.md#почта-встроенный-mcp-сервер — the built-in mail server
 */
final class MailSettings {
    /**
     * @param array<array-key, mixed> $env
     * @return array<non-empty-string, string>|null
     */
    public static function headers(array $env): ?array {
        $host = Env::raw($env, 'MAIL_HOST');
        $user = Env::raw($env, 'MAIL_USER');
        $password = Env::raw($env, 'MAIL_PASSWORD');
        if ($host === null || $user === null || $password === null) {
            return null;
        }
        $headers = ['x-mail-host' => $host, 'x-mail-user' => $user, 'x-mail-password' => $password];
        foreach (['MAIL_IMAP_PORT' => 'x-mail-imap-port', 'MAIL_SMTP_PORT' => 'x-mail-smtp-port', 'MAIL_FROM' => 'x-mail-from', 'MAIL_AUTHSERV_ID' => 'x-mail-authserv-id'] as $key => $header) {
            $value = Env::raw($env, $key);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
}
