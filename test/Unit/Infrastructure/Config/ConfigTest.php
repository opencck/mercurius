<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Config;

use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {
    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function env(array $override = []): array {
        return array_merge([
            'POSTGRES_HOST' => 'postgres',
            'POSTGRES_DB' => 'mercurius',
            'POSTGRES_USER' => 'mercurius',
            'POSTGRES_PASSWORD' => 'secret',
        ], $override);
    }

    public function testDefaults(): void {
        $config = Config::fromEnv($this->env());

        self::assertSame('0.0.0.0', $config->httpHost);
        self::assertSame(8080, $config->httpPort);
        self::assertNull($config->appUrl);
        self::assertSame('info', $config->logLevel);
        self::assertSame('UTC', $config->timezone);
        self::assertSame('postgres', $config->postgres->host);
        self::assertSame(5432, $config->postgres->port);
        self::assertSame('mercurius', $config->postgres->database);
        self::assertSame('mercurius', $config->postgres->user);
        self::assertSame('secret', $config->postgres->password());
    }

    public function testOverrides(): void {
        $config = Config::fromEnv($this->env([
            'HTTP_HOST' => '127.0.0.1',
            'HTTP_PORT' => '9000',
            'APP_URL' => 'https://mercurius.example.com/',
            'LOG_LEVEL' => 'DEBUG',
            'SYS_TIMEZONE' => 'Europe/Moscow',
            'POSTGRES_PORT' => '6543',
        ]));

        self::assertSame('127.0.0.1', $config->httpHost);
        self::assertSame(9000, $config->httpPort);
        self::assertSame('https://mercurius.example.com', $config->appUrl);
        self::assertSame('debug', $config->logLevel);
        self::assertSame('Europe/Moscow', $config->timezone);
        self::assertSame(6543, $config->postgres->port);
    }

    public function testEmptyAppUrlMeansNoPublicAddress(): void {
        self::assertNull(Config::fromEnv($this->env(['APP_URL' => '']))->appUrl);
    }

    public function testMissingRequiredVariableIsNamed(): void {
        $env = $this->env();
        unset($env['POSTGRES_PASSWORD']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('POSTGRES_PASSWORD');
        Config::fromEnv($env);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidValues(): iterable {
        yield 'port not a number' => ['HTTP_PORT', 'abc'];
        yield 'port out of range' => ['HTTP_PORT', '70000'];
        yield 'unknown log level' => ['LOG_LEVEL', 'loud'];
        yield 'unknown timezone' => ['SYS_TIMEZONE', 'Mars/Olympus'];
        yield 'app url without scheme' => ['APP_URL', 'mercurius.example.com'];
    }

    /** @dataProvider invalidValues */
    public function testInvalidValueIsNamed(string $name, string $value): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($name);
        Config::fromEnv($this->env([$name => $value]));
    }

    public function testSecretIsNotInStringForm(): void {
        $config = Config::fromEnv($this->env());

        self::assertStringNotContainsString('secret', print_r($config->postgres, true));
    }
}
