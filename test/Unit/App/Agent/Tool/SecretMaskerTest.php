<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use OpenCCK\App\Agent\Tool\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase {
    public function testSecretNamedArgumentsAreMaskedAtAnyDepth(): void {
        $masked = SecretMasker::maskArgs([
            'url' => 'https://api.example.com/v1',
            'headers' => ['X-Api-Key' => 'abcd1234', 'Authorization' => 'Basic dXNlcjpw', 'Accept' => 'application/json'],
            'body' => ['user' => 'bob', 'password' => 'hunter2', 'nested' => ['client_secret' => 'xyz']],
        ]);
        $json = json_encode($masked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach (['abcd1234', 'dXNlcjpw', 'hunter2', 'xyz'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertStringContainsString('application/json', $json);
        self::assertStringContainsString('bob', $json);
    }

    public function testPasswordsSaidInRussianAreMasked(): void {
        self::assertSame('пароль от роутера admin / ***', SecretMasker::mask('пароль от роутера admin / Qwerty-EVAL-2026'));
        self::assertSame('Пароль от роутера: ***', SecretMasker::mask('Пароль от роутера: Qwerty-EVAL-2026'));
        self::assertSame('роутер: логин admin, пароль ***', SecretMasker::mask('роутер: логин admin, пароль Qwerty-EVAL-2026'));
        self::assertSame('токен бота: ***', SecretMasker::mask('токен бота: 123456:ABC-def'));
        self::assertSame('Сменить пароль от почты завтра', SecretMasker::mask('Сменить пароль от почты завтра'), 'no value — nothing to hide');
        self::assertSame('пароль должен быть длиннее 12 символов', SecretMasker::mask('пароль должен быть длиннее 12 символов'));
    }

    public function testJsonInAStringIsMasked(): void {
        $masked = SecretMasker::mask('{"password": "hunter2", "X-Api-Key":"abcd1234", "name": "ok"}');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('abcd1234', $masked);
        self::assertStringContainsString('"name": "ok"', $masked);
    }

    public function testCommandLinePasswordsAndUrlIdsAreMasked(): void {
        $masked = SecretMasker::mask('mysql -uroot -phunter22 db; psql --password s3cretpw; vless://0b5c8a1e-1111-4222-8333-944455556666@host:443?type=ws');

        self::assertStringNotContainsString('hunter22', $masked);
        self::assertStringNotContainsString('s3cretpw', $masked);
        self::assertStringNotContainsString('0b5c8a1e-1111-4222-8333-944455556666', $masked);
        self::assertStringContainsString('mysql -uroot', $masked);
        self::assertSame('mkdir -p build && ssh -p 22 host', SecretMasker::mask('mkdir -p build && ssh -p 22 host'));
    }

    public function testUrlCredentialsAndSecretQueryValuesAreMasked(): void {
        $masked = SecretMasker::mask('git push https://user:hunter2@github.com/x.git; curl "https://api.example.com/x?access_key=s3cr3t&page=2"');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('s3cr3t', $masked);
        self::assertStringContainsString('github.com/x.git', $masked);
        self::assertStringContainsString('page=2', $masked);
    }
    public function testAKnownSecretIsMaskedByItsValueWhateverItLooksLike(): void {
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a password like any word is caught by its value
        SecretMasker::remember('correct horse battery');
        SecretMasker::remember('short');
        $masked = SecretMasker::mask('the phrase was correct horse battery, and short stays');

        self::assertStringNotContainsString('correct horse battery', $masked);
        self::assertStringContainsString('short stays', $masked, 'a value too short to tell from a word is not taken');
    }

    public function testTelegramTokensAndPrivateKeysAreMasked(): void {
        // ref: references/hermes-agent/agent/redact.py@e96221fa:L620-L640 — the shapes of a bot token and a PEM key
        $masked = SecretMasker::mask("url https://api.telegram.org/bot1234567890:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/getMe\n-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\nAAAAQ\n-----END OPENSSH PRIVATE KEY-----\nend");

        self::assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $masked);
        self::assertStringContainsString('bot1234567890:***', $masked);
        self::assertStringNotContainsString('b3BlbnNzaC1rZXktdjEAAAAA', $masked);
        self::assertStringContainsString('end', $masked);
        self::assertSame('a time 12:30 and an id 12345678:short', SecretMasker::mask('a time 12:30 and an id 12345678:short'));
    }

    public function testTheLogHidesSecretsInItsMessageAndContext(): void {
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a log line does not carry a secret
        SecretMasker::remember('log-secret-value-123');
        $record = new \Monolog\LogRecord(new \DateTimeImmutable(), 'mercurius', \Monolog\Level::Info, 'failed with log-secret-value-123', ['error' => 'token=abcdef123456 here', 'nested' => ['x' => 'log-secret-value-123'], 'n' => 5]);
        $clean = (new \OpenCCK\Infrastructure\Logger\SecretProcessor())($record);

        self::assertStringNotContainsString('log-secret-value-123', $clean->message);
        self::assertStringNotContainsString('abcdef123456', json_encode($clean->context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('log-secret-value-123', json_encode($clean->context, JSON_THROW_ON_ERROR));
        self::assertSame(5, $clean->context['n']);
    }
    public function testAMessageWithAHashOfACommitDoesNotCarryASecret(): void {
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a message is deleted for a secret, not for a hash
        self::assertFalse(SecretMasker::carriesSecret('посмотри коммит 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e'));
        self::assertFalse(SecretMasker::carriesSecret('картинка aGVsbG8gd29ybGQgdGhpcyBpcyBqdXN0IGJhc2U2NCBkYXRhIG5vdCBhIGtleQ=='));
        self::assertTrue(SecretMasker::carriesSecret('токен: 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a'));
        self::assertTrue(SecretMasker::carriesSecret('ключ sk-or-v1-0123456789abcdef0123456789abcdef'));
        self::assertNotSame('коммит 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e', SecretMasker::mask('коммит 3802092a1f4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e'), 'output is still masked as before');
    }
}
