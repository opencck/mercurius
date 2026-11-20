<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

/**
 * Application configuration built once from environment variables.
 *
 * spec: brief/architecture.md#устройство-приложения-на-opencck — configuration comes from .env
 */
final class Config {
    /**
     * @param int<1, 65535> $httpPort
     * @param 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency' $logLevel
     * @param non-empty-string $timezone
     */
    private function __construct(
        public readonly string $httpHost,
        public readonly int $httpPort,
        public readonly ?string $appUrl,
        public readonly string $logLevel,
        public readonly string $timezone,
        public readonly PostgresSettings $postgres,
    ) {}

    /**
     * @param array<array-key, mixed> $env usually $_ENV after Dotenv::safeLoad()
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        return new self(
            httpHost: self::string($env, 'HTTP_HOST', '0.0.0.0'),
            httpPort: self::port($env, 'HTTP_PORT', 8080),
            appUrl: self::url($env, 'APP_URL'),
            logLevel: self::logLevel($env),
            timezone: self::timezone($env),
            postgres: new PostgresSettings(
                host: self::required($env, 'POSTGRES_HOST'),
                port: self::port($env, 'POSTGRES_PORT', 5432),
                database: self::required($env, 'POSTGRES_DB'),
                user: self::required($env, 'POSTGRES_USER'),
                password: self::required($env, 'POSTGRES_PASSWORD'),
            ),
        );
    }

    /** @param array<array-key, mixed> $env */
    private static function raw(array $env, string $name): ?string {
        $value = $env[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<array-key, mixed> $env */
    private static function string(array $env, string $name, string $default): string {
        return self::raw($env, $name) ?? $default;
    }

    /** @param array<array-key, mixed> $env */
    private static function required(array $env, string $name): string {
        return self::raw($env, $name) ?? throw ConfigException::missing($name);
    }

    /**
     * @param array<array-key, mixed> $env
     * @param int<1, 65535> $default
     * @return int<1, 65535>
     */
    private static function port(array $env, string $name, int $default): int {
        $value = self::raw($env, $name);
        if ($value === null) {
            return $default;
        }
        $port = ctype_digit($value) ? (int) $value : 0;
        if ($port < 1 || $port > 65535) {
            throw ConfigException::invalid($name, 'a port number 1-65535');
        }
        return $port;
    }

    /** @param array<array-key, mixed> $env */
    private static function url(array $env, string $name): ?string {
        $value = self::raw($env, $name);
        if ($value === null) {
            return null;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw ConfigException::invalid($name, 'an absolute http(s) URL');
        }
        return rtrim($value, '/');
    }

    /**
     * @param array<array-key, mixed> $env
     * @return 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'
     */
    private static function logLevel(array $env): string {
        return match (strtolower(self::string($env, 'LOG_LEVEL', 'info'))) {
            'debug' => 'debug',
            'info' => 'info',
            'notice' => 'notice',
            'warning' => 'warning',
            'error' => 'error',
            'critical' => 'critical',
            'alert' => 'alert',
            'emergency' => 'emergency',
            default => throw ConfigException::invalid('LOG_LEVEL', 'debug|info|notice|warning|error|critical|alert|emergency'),
        };
    }

    /**
     * @param array<array-key, mixed> $env
     * @return non-empty-string
     */
    private static function timezone(array $env): string {
        $value = self::string($env, 'SYS_TIMEZONE', 'UTC');
        if ($value === '' || !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            throw ConfigException::invalid('SYS_TIMEZONE', 'an IANA time zone identifier');
        }
        return $value;
    }
}
