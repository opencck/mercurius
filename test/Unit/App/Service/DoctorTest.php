<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\Doctor;

final class DoctorTest extends AsyncTestCase {
    /** @param array<string, string> $env */
    private static function results(array $env): string {
        return Doctor::render((new Doctor($env, null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir()))->run());
    }

    public function testAGoodSettingPassesAndSecretsAreNeverShown(): void {
        $text = self::results([
            'TELEGRAM_BOT_TOKEN' => '123456:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'TELEGRAM_ADMIN_ID' => '42',
            'OPENROUTER_API_KEY' => 'sk-or-v1-secretvalue',
            'SECRETS_KEY' => base64_encode(str_repeat('k', 32)),
            'APP_URL' => 'https://bot.example',
            'TELEGRAM_WEBHOOK_SECRET' => 'hook-secret',
            'OWNER_TIMEZONE' => 'Europe/Moscow',
        ]);

        self::assertStringContainsString('✅ Токен бота: задан', $text);
        self::assertStringContainsString('✅ Хранилище секретов: ключ 32 байта', $text);
        self::assertStringContainsString('✅ Telegram: webhook на https://bot.example', $text);
        self::assertStringContainsString('❌ База: нет соединения', $text);
        self::assertStringContainsString('⚠️ Песочница: SANDBOX_TOKEN пуст — выключен', $text);
        foreach (['AAAAAAAAAAAAAAAA', 'secretvalue', 'hook-secret', base64_encode(str_repeat('k', 32))] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testTypicalMistakesAreNamed(): void {
        $text = self::results([
            'TELEGRAM_BOT_TOKEN' => 'not-a-token',
            'TELEGRAM_ADMIN_ID' => '@owner',
            'SECRETS_KEY' => 'short',
            'APP_URL' => 'https://bot.example',
            'OWNER_TIMEZONE' => 'Mars/Olympus',
            'QUIET_HOURS' => 'ночью',
            'DIGEST_TIME' => '9 утра',
        ]);

        foreach ([
            '❌ Токен бота', '❌ Владелец', '❌ Модели: нужен OPENROUTER_API_KEY или OPENAI_API_KEY',
            '❌ Хранилище секретов: SECRETS_KEY — 32 байта', '❌ Telegram: APP_URL задан, а TELEGRAM_WEBHOOK_SECRET пуст',
            '❌ Часовой пояс: OWNER_TIMEZONE не распознан', '⚠️ Тихие часы', '⚠️ Сводка',
        ] as $line) {
            self::assertStringContainsString($line, $text);
        }
        self::assertMatchesRegularExpression('~Ошибок: \d+, предупреждений: \d+\.~', $text);
    }
}
