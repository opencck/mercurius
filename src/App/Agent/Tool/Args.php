<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * Typed reads of validated tool arguments.
 */
final class Args {
    /** @param array<string, mixed> $args */
    public static function string(array $args, string $key): ?string {
        return isset($args[$key]) && is_string($args[$key]) ? $args[$key] : null;
    }

    /** @param array<string, mixed> $args */
    public static function int(array $args, string $key): ?int {
        return isset($args[$key]) && is_int($args[$key]) ? $args[$key] : null;
    }

    /**
     * String values of an object argument; other values are skipped.
     *
     * @param array<string, mixed> $args
     * @return array<non-empty-string, string>
     */
    public static function stringMap(array $args, string $key): array {
        $value = isset($args[$key]) && is_array($args[$key]) ? $args[$key] : [];
        $map = [];
        foreach (array_keys($value) as $name) {
            $name = (string) $name;
            if ($name !== '' && isset($value[$name]) && is_string($value[$name])) {
                $map[$name] = $value[$name];
            }
        }
        return $map;
    }

    /**
     * String items of a list argument; other items are skipped.
     *
     * @param array<string, mixed> $args
     * @return list<string>
     */
    public static function strings(array $args, string $key): array {
        $value = isset($args[$key]) && is_array($args[$key]) ? $args[$key] : [];
        $list = [];
        foreach (array_keys($value) as $i) {
            if (isset($value[$i]) && is_string($value[$i])) {
                $list[] = $value[$i];
            }
        }
        return $list;
    }
}
