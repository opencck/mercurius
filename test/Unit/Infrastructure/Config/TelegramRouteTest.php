<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Config;

use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use PHPUnit\Framework\TestCase;

/**
 * How the application reaches Telegram: directly, through a proxy, through VLESS; the cloud Bot API or a local server.
 */
final class TelegramRouteTest extends TestCase {
    private const BASE = ['TELEGRAM_BOT_TOKEN' => '123456:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'TELEGRAM_ADMIN_ID' => '42'];

    /** @param array<string, string> $env */
    private static function settings(array $env): TelegramSettings {
        return TelegramSettings::fromEnv(self::BASE + $env, null);
    }

    public function testDirectAndTheCloudByDefault(): void {
        $telegram = self::settings([]);

        self::assertNull($telegram->route());
        self::assertNull($telegram->apiUrl);
        self::assertSame(20_000_000, $telegram->fileLimit, 'the cloud gives a bot files up to 20 MB');
    }

    public function testAProxyOrVless(): void {
        self::assertSame('socks5h://user:pass@proxy.example:1080', self::settings(['TELEGRAM_PROXY' => 'socks5h://user:pass@proxy.example:1080'])->route());
        self::assertSame('http://10.0.0.2:3128', self::settings(['TELEGRAM_PROXY' => 'http://10.0.0.2:3128'])->route());
        self::assertSame('vless://id@vpn.example:443?security=tls', self::settings(['TELEGRAM_PROXY' => 'vless', 'VLESS_URI' => 'vless://id@vpn.example:443?security=tls'])->route(), 'the same VLESS as the models');
        self::assertStringNotContainsString('pass', print_r(self::settings(['TELEGRAM_PROXY' => 'socks5h://user:pass@proxy.example:1080']), true), 'a proxy password is not printed');

        foreach ([['TELEGRAM_PROXY' => 'vless'], ['TELEGRAM_PROXY' => 'ftp://x:1'], ['TELEGRAM_PROXY' => 'proxy.example:1080']] as $bad) {
            try {
                self::settings($bad);
                self::fail(json_encode($bad, JSON_THROW_ON_ERROR) . ' was taken');
            } catch (ConfigException $e) {
                self::assertStringContainsString('TELEGRAM_PROXY', $e->getMessage());
            }
        }
    }

    public function testALocalServerTakesBiggerFiles(): void {
        $local = self::settings(['TELEGRAM_API_URL' => 'http://telegram-api:8081/']);

        self::assertSame('http://telegram-api:8081', $local->apiUrl);
        self::assertSame(200_000_000, $local->fileLimit, '200 MB by default: the disk is not endless');
        self::assertSame('/var/lib/telegram-bot-api', $local->filesRoot);
        self::assertSame(1_000_000_000, self::settings(['TELEGRAM_API_URL' => 'http://telegram-api:8081', 'TELEGRAM_FILE_LIMIT_MB' => '1000'])->fileLimit);
        self::assertSame(20_000_000, self::settings(['TELEGRAM_FILE_LIMIT_MB' => '1000'])->fileLimit, 'the cloud stays at 20 MB whatever is set');

        $this->expectException(ConfigException::class);
        self::settings(['TELEGRAM_API_URL' => 'telegram-api:8081']);
    }

    public function testPollingWithAPublicAddressKeepsTheAddressForOtherUses(): void {
        $env = self::BASE + ['TELEGRAM_WEBHOOK_SECRET' => 'secret-secret-secret'];

        self::assertSame('https://bot.example/telegram/webhook', TelegramSettings::fromEnv($env, 'https://bot.example')->webhookUrl, 'a webhook by default with APP_URL');
        $polling = TelegramSettings::fromEnv(self::BASE + ['TELEGRAM_MODE' => 'polling'], 'https://bot.example');
        self::assertNull($polling->webhookUrl, 'long polling though APP_URL is set: the agent network still uses it');
        self::assertNull($polling->webhookSecret(), 'no secret needed for polling');

        $this->expectException(ConfigException::class);
        TelegramSettings::fromEnv(self::BASE + ['TELEGRAM_MODE' => 'webhook'], null);
    }
}
