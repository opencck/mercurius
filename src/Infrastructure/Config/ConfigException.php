<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use RuntimeException;

/**
 * Configuration error. The message always names the environment variable and never contains its value.
 */
final class ConfigException extends RuntimeException {
    public static function missing(string $name): self {
        return new self(sprintf('Environment variable %s is required', $name));
    }

    public static function invalid(string $name, string $expected): self {
        return new self(sprintf('Environment variable %s is invalid: expected %s', $name, $expected));
    }
}
