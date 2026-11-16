<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * Telegram bot settings. With a public APP_URL the bot uses a webhook (secret required), otherwise long polling.
 */
final class TelegramSettings {
    private readonly string $token;
    private readonly ?string $webhookSecret;

    /** @param non-empty-string $ownerId */
    private function __construct(
        #[SensitiveParameter] string $token,
        public readonly string $ownerId,
        public readonly ?string $webhookUrl,
        #[SensitiveParameter] ?string $webhookSecret,
    ) {
        $this->token = $token;
        $this->webhookSecret = $webhookSecret;
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

        $secret = null;
        if ($appUrl !== null) {
            $secret = Env::required($env, 'TELEGRAM_WEBHOOK_SECRET');
            if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
                throw ConfigException::invalid('TELEGRAM_WEBHOOK_SECRET', '16-256 characters A-Z, a-z, 0-9, _ and -');
            }
        }

        return new self($token, $ownerId, $appUrl === null ? null : $appUrl . '/telegram/webhook', $secret);
    }

    public function token(): string {
        return $this->token;
    }

    public function webhookSecret(): ?string {
        return $this->webhookSecret;
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array {
        return ['token' => '***', 'ownerId' => $this->ownerId, 'webhookUrl' => $this->webhookUrl, 'webhookSecret' => $this->webhookSecret === null ? null : '***'];
    }
}
