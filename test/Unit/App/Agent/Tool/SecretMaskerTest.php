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

    public function testJsonInAStringIsMasked(): void {
        $masked = SecretMasker::mask('{"password": "hunter2", "X-Api-Key":"abcd1234", "name": "ok"}');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('abcd1234', $masked);
        self::assertStringContainsString('"name": "ok"', $masked);
    }

    public function testUrlCredentialsAndSecretQueryValuesAreMasked(): void {
        $masked = SecretMasker::mask('git push https://user:hunter2@github.com/x.git; curl "https://api.example.com/x?access_key=s3cr3t&page=2"');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('s3cr3t', $masked);
        self::assertStringContainsString('github.com/x.git', $masked);
        self::assertStringContainsString('page=2', $masked);
    }
}
