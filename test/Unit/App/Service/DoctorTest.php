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

    public function testTheLocalBotApiIsCheckedByTheBotNotByThePort(): void {
        // spec: brief/channels.md#здоровье-локального-сервера-bot-api — a hung server answers its root page, not getMe
        $token = '123456:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $env = ['TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_API_URL' => 'http://telegram-api:8081'];
        $alive = new \OpenCCK\Test\Support\FakeHttpClient(['http://telegram-api:8081/bot' . $token . '/getMe' => [200, [], '{"ok":true}']]);
        $hung = new \OpenCCK\Test\Support\FakeHttpClient(['http://telegram-api:8081/' => [404, [], 'not found']]);

        $up = Doctor::render((new Doctor($env, null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir(), new \Amp\Http\Client\HttpClient($alive, [])))->run());
        $down = Doctor::render((new Doctor($env, null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir(), new \Amp\Http\Client\HttpClient($hung, [])))->run());

        self::assertStringContainsString('✅ telegram-api: отвечает', $up);
        self::assertStringContainsString('❌ telegram-api:', $down);
        self::assertStringNotContainsString('AAAAAAAAAAAAAAAA', $up . $down);
        self::assertStringNotContainsString('telegram-api', self::results(['TELEGRAM_BOT_TOKEN' => $token]), 'the cloud Bot API needs no module');
    }

    public function testABrokenCommandJournalFails(): void {
        // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — doctor checks the chain of the command journal
        $audit = new \OpenCCK\Test\Support\InMemoryCommandAuditRepository();
        $audit->append(['principal' => 1, 'run' => null, 'server' => 'web', 'command' => 'id', 'class' => 'read', 'event' => 'done', 'exit' => 0, 'ms' => 5, 'error' => null]);
        $doctor = new Doctor([], null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir(), null, $audit);
        self::assertStringContainsString('✅ ' . \Mercurius\I18n\t('service.doctor.audit'), Doctor::render($doctor->run()));

        $audit->tamper(0, 'id; curl evil');

        self::assertStringContainsString('❌ ' . \Mercurius\I18n\t('service.doctor.audit') . ': ' . sprintf(\Mercurius\I18n\t('service.doctor.audit_broken'), 1), Doctor::render($doctor->run()));
    }

    public function testTypicalMistakesAreNamed(): void {
        $text = self::results([
            'TELEGRAM_BOT_TOKEN' => 'not-a-token',
            'TELEGRAM_ADMIN_ID' => '@owner',
            'SECRETS_KEY' => 'short',
            'APP_URL' => 'https://bot.example',
            'OWNER_TIMEZONE' => 'Mars/Olympus',
            'QUIET_HOURS' => 'ночью',
        ]);

        foreach ([
            '❌ Токен бота', '❌ Владелец', '❌ Модели: нужен OPENROUTER_API_KEY, OPENAI_API_KEY, ANTHROPIC_API_KEY, AWS_BEARER_TOKEN_BEDROCK, GEMINI_API_KEY или VERTEX_CREDENTIALS',
            '❌ Хранилище секретов: SECRETS_KEY — 32 байта', '❌ Telegram: APP_URL задан, а TELEGRAM_WEBHOOK_SECRET пуст',
            '❌ Часовой пояс: OWNER_TIMEZONE не распознан', '⚠️ Тихие часы',
        ] as $line) {
            self::assertStringContainsString($line, $text);
        }
        self::assertMatchesRegularExpression('~Ошибок: \d+, предупреждений: \d+\.~', $text);
    }

    public function testAGoogleKeyOfTheWrongKindIsNamed(): void {
        // spec: brief/models.md#провайдеры-gemini-и-vertex
        $text = self::results(['GEMINI_API_KEY' => '{"type":"service_account"}', 'VERTEX_CREDENTIALS' => 'AIza-not-json']);

        self::assertStringContainsString('✅ Модели: ключ Gemini API задан', $text);
        self::assertStringContainsString('❌ GEMINI_API_KEY: это JSON, а не ключ', $text);
        self::assertStringContainsString('❌ Vertex: ', $text);
        self::assertStringContainsString('VERTEX_CREDENTIALS', $text);
        self::assertStringNotContainsString('AIza-not-json', $text);
    }

    public function testTheDataFolderMustBeWritable(): void {
        // spec: brief/operations.md#экспорт-и-импорт-агента — archives and marks of the application go into storage/app
        $ok = Doctor::render((new Doctor([], null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir()))->run());
        self::assertStringContainsString('✅ ' . \Mercurius\I18n\t('service.doctor.storage'), $ok);

        $missing = sys_get_temp_dir() . '/no-such-storage-' . bin2hex(random_bytes(4));
        $failed = Doctor::render((new Doctor([], null, dirname(__DIR__, 4) . '/migrations', $missing))->run());
        self::assertStringContainsString('❌ ' . \Mercurius\I18n\t('service.doctor.storage') . ': ' . \Mercurius\I18n\t('service.doctor.storage_readonly'), $failed);
    }

    public function testAnOutdatedStoredSettingIsNamed(): void {
        // spec: brief/operations.md#настройки-агента — a stored key the release no longer has
        $doctor = new Doctor([], null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir(), null, null, static fn(): array => ['OLD_LIMIT']);
        self::assertStringContainsString('⚠️ ' . \Mercurius\I18n\t('service.doctor.settings_unknown') . ': OLD_LIMIT', Doctor::render($doctor->run()));
        self::assertStringNotContainsString(\Mercurius\I18n\t('service.doctor.settings_unknown'), self::results([]), 'no outdated keys, no line');
    }

    /** spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — doctor names connections that do not work */
    public function testConnectionsThatDoNotWorkAreNamed(): void {
        $ok = new \OpenCCK\Domain\Mcp\McpServer(1, 'docs', 'https://a.example/mcp', null, \OpenCCK\Domain\Mcp\McpServer::CONNECTED, null, context: 'member:1');
        $bad = new \OpenCCK\Domain\Mcp\McpServer(2, 'github', 'https://b.example/mcp', null, \OpenCCK\Domain\Mcp\McpServer::ERROR, null, 'Connection refused', context: 'project:3');
        $doctor = new Doctor([], null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir(), mcp: static fn(): array => [$ok, $bad]);

        $text = Doctor::render($doctor->run());

        self::assertStringContainsString('github (project:3): ' . \Mercurius\I18n\t('service.mcp_commands.state.error') . ' — Connection refused', $text);
        self::assertStringNotContainsString('docs (member:1)', $text);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.doctor.mcp_ok'), 1), Doctor::render((new Doctor([], null, dirname(__DIR__, 4) . '/migrations', sys_get_temp_dir(), mcp: static fn(): array => [$ok]))->run()));
    }
}
