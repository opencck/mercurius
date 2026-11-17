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
}
