<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

/**
 * Typed readers of environment variables. Errors name the variable and never contain the value.
 */
final class Env {
    /**
     * @param array<array-key, mixed> $env
     * @return non-empty-string|null
     */
    public static function raw(array $env, string $name): ?string {
        $value = $env[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<array-key, mixed> $env
     * @return non-empty-string
     */
    public static function required(array $env, string $name): string {
        return self::raw($env, $name) ?? throw ConfigException::missing($name);
    }

    /**
     * @param array<array-key, mixed> $env
     * @param positive-int $default
     * @param positive-int $max
     * @return positive-int
     */
    public static function positiveInt(array $env, string $name, int $default, int $max = PHP_INT_MAX): int {
        $value = self::raw($env, $name);
        if ($value === null) {
            return $default;
        }
        $number = ctype_digit($value) ? (int) $value : 0;
        if ($number < 1 || $number > $max) {
            throw ConfigException::invalid($name, sprintf('an integer 1-%d', $max));
        }
        return $number;
    }

    /**
     * Lower-cased value that must be one of $allowed; callers narrow it further with match().
     *
     * @param array<array-key, mixed> $env
     * @param list<string> $allowed
     */
    public static function oneOf(array $env, string $name, array $allowed, string $default): string {
        $value = strtolower(self::raw($env, $name) ?? $default);
        if (!in_array($value, $allowed, true)) {
            throw ConfigException::invalid($name, implode('|', $allowed));
        }
        return $value;
    }

    /**
     * A size in bytes: plain number or with k/m/g suffix (powers of 1024), as Docker writes it.
     *
     * @param array<array-key, mixed> $env
     * @param positive-int $default
     * @return positive-int
     * @throws ConfigException
     */
    public static function bytes(array $env, string $name, int $default): int {
        $value = self::raw($env, $name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^(\d+)([kmg]?)$/i', $value, $match) !== 1) {
            throw ConfigException::invalid($name, 'a size like 512m, 2g or a number of bytes');
        }
        $bytes = (int) $match[1] * match (strtolower($match[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
        if ($bytes < 1) {
            throw ConfigException::invalid($name, 'a positive size');
        }
        return $bytes;
    }
}
