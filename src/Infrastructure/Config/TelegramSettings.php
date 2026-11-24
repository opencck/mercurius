<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * Telegram bot settings. With a public APP_URL the bot uses a webhook (secret required), otherwise long polling.
 * The way to Telegram: directly, through a proxy (TELEGRAM_PROXY: http, socks5, socks5h) or through the VLESS of the
 * models (TELEGRAM_PROXY=vless); the cloud Bot API or a local telegram-bot-api server (TELEGRAM_API_URL), which takes
 * files up to 2000 MB.
 *
 * spec: brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api
 */
final class TelegramSettings {
    /** The cloud Bot API gives a bot files up to 20 MB. */
    public const CLOUD_FILE_LIMIT = 20_000_000;
    public const LOCAL_FILES = '/var/lib/telegram-bot-api';
    private readonly string $token;
    private readonly ?string $webhookSecret;
    private readonly ?string $route;

    /** @param non-empty-string $ownerId */
    private function __construct(
        #[SensitiveParameter] string $token,
        public readonly string $ownerId,
        public readonly ?string $webhookUrl,
        #[SensitiveParameter] ?string $webhookSecret,
        #[SensitiveParameter] ?string $route = null,
        /** a local telegram-bot-api server; null — the cloud Bot API */
        public readonly ?string $apiUrl = null,
        /** the largest file the bot downloads, bytes */
        public readonly int $fileLimit = self::CLOUD_FILE_LIMIT,
        /** where a local server keeps files (mounted read-only into the application at the same path) */
        public readonly string $filesRoot = self::LOCAL_FILES,
    ) {
        $this->token = $token;
        $this->webhookSecret = $webhookSecret;
        $this->route = $route;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env, ?string $appUrl): self {
        $token = Env::required($env, 'TELEGRAM_BOT_TOKEN');
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
            throw ConfigException::invalid('TELEGRAM_BOT_TOKEN', 'a bot token like 123456:ABC...');
        }
        $ownerId = Env::required($env, 'TELEGRAM_ADMIN_ID');
        if (!ctype_digit($ownerId)) {
            throw ConfigException::invalid('TELEGRAM_ADMIN_ID', 'a numeric Telegram user id');
        }

        // TELEGRAM_MODE: webhook (by default with APP_URL) or polling — APP_URL stays for the agent network either way
        $mode = Env::oneOf($env, 'TELEGRAM_MODE', ['webhook', 'polling'], $appUrl === null ? 'polling' : 'webhook');
        if ($mode === 'webhook' && $appUrl === null) {
            throw ConfigException::invalid('TELEGRAM_MODE', 'polling, or webhook with APP_URL set');
        }
        $webhook = $mode === 'webhook' ? $appUrl : null;
        $secret = null;
        if ($webhook !== null) {
            $secret = Env::required($env, 'TELEGRAM_WEBHOOK_SECRET');
            if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
                throw ConfigException::invalid('TELEGRAM_WEBHOOK_SECRET', '16-256 characters A-Z, a-z, 0-9, _ and -');
            }
        }

        $apiUrl = Env::raw($env, 'TELEGRAM_API_URL');
        if ($apiUrl !== null && preg_match('#^https?://[^\s/]+/?$#', $apiUrl) !== 1) {
            throw ConfigException::invalid('TELEGRAM_API_URL', 'the address of a local telegram-bot-api, like http://telegram-api:8081');
        }
        $limit = $apiUrl === null
            ? self::CLOUD_FILE_LIMIT
            : Env::positiveInt($env, 'TELEGRAM_FILE_LIMIT_MB', 200, 2000) * 1_000_000;
        return new self(
            $token,
            $ownerId,
            $webhook === null ? null : $webhook . '/telegram/webhook',
            $secret,
            self::parseRoute($env),
            $apiUrl === null ? null : rtrim($apiUrl, '/'),
            $limit,
            Env::raw($env, 'TELEGRAM_API_FILES') ?? self::LOCAL_FILES,
        );
    }

    /**
     * TELEGRAM_PROXY: vless — the VLESS_URI of the models; else an http(s), socks5 or socks5h URI. Empty — the proxy
     * of the installation (DEFAULT_PROXY), and directly when that one is empty too; the word direct — directly.
     * spec: brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api
     *
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    private static function parseRoute(array $env): ?string {
        $name = Env::raw($env, 'TELEGRAM_PROXY') === null ? 'DEFAULT_PROXY' : 'TELEGRAM_PROXY';
        $proxy = Env::raw($env, $name);
        // the word direct in TELEGRAM_PROXY keeps Telegram off the proxy of the installation
        if ($proxy === null || ($name === 'TELEGRAM_PROXY' && strtolower($proxy) === 'direct')) {
            return null;
        }
        if ($name === 'DEFAULT_PROXY') {
            if (preg_match('#^(https?|socks5h?)://[^\s/]+$#i', $proxy) !== 1) {
                throw ConfigException::invalid($name, 'a proxy like socks5h://user:pass@host:1080 or http://host:3128');
            }
            return $proxy;
        }
        if (strtolower($proxy) === 'vless') {
            return Env::raw($env, 'VLESS_URI') ?? throw ConfigException::invalid('TELEGRAM_PROXY', 'vless with VLESS_URI set');
        }
        if (preg_match('#^(https?|socks5h?)://[^\s/]+$#i', $proxy) !== 1) {
            throw ConfigException::invalid('TELEGRAM_PROXY', 'vless, direct, or a proxy like socks5h://user:pass@host:1080 or http://host:3128');
        }
        return $proxy;
    }

    /** The route to Telegram for the HTTP client (a proxy or VLESS URI); null — directly. */
    public function route(): ?string {
        return $this->route;
    }

    public function token(): string {
        return $this->token;
    }

    public function webhookSecret(): ?string {
        return $this->webhookSecret;
    }

    /** @return array<string, string|int|null> */
    public function __debugInfo(): array {
        return ['token' => '***', 'ownerId' => $this->ownerId, 'webhookUrl' => $this->webhookUrl, 'webhookSecret' => $this->webhookSecret === null ? null : '***', 'route' => $this->route === null ? null : '***', 'apiUrl' => $this->apiUrl, 'fileLimit' => $this->fileLimit];
    }
}
