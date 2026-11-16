<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * JSON with object keys sorted at every level: equal arguments give equal strings whatever the key order.
 */
final class CanonicalJson {
    public static function encode(mixed $value): string {
        return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sortKeys(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::sortKeys(...), $value);
    }
}
