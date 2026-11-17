<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use function Mercurius\I18n\t;

/**
 * The subset of JSON Schema that tool arguments use: type, properties, required, enum, items,
 * additionalProperties: false, minimum, maximum, maxLength. Errors are in Russian, for the model.
 *
 * ref: references/hermes-agent/tools/arg_validation.py@e96221f:L100 (check_tool_args) — required and unknown
 *      arguments by the schema before the handler; we also check types instead of coercing them
 */
final class JsonSchema {
    /**
     * @param array<array-key, mixed> $schema
     * @return list<string> errors, empty when the value is valid
     */
    public static function validate(array $schema, mixed $value): array {
        $errors = [];
        self::check($schema, $value, '', $errors);
        return $errors;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string> $errors
     */
    private static function check(array $schema, mixed $value, string $path, array &$errors): void {
        $type = self::string($schema, 'type');
        if ($type !== null && !self::hasType($type, $value)) {
            $errors[] = self::at($path, t('tool.json_schema.ozhidaetsya') . $type);
            return;
        }

        $enum = self::array($schema, 'enum');
        if ($enum !== null && !in_array($value, $enum, true)) {
            $errors[] = self::at($path, t('tool.json_schema.dopustimo_odno_iz') . implode(', ', array_map(self::show(...), $enum)));
            return;
        }

        if (is_int($value) || is_float($value)) {
            $minimum = self::number($schema, 'minimum');
            $maximum = self::number($schema, 'maximum');
            if ($minimum !== null && $value < $minimum) {
                $errors[] = self::at($path, sprintf(t('tool.json_schema.ne_menshe'), $minimum));
            }
            if ($maximum !== null && $value > $maximum) {
                $errors[] = self::at($path, sprintf(t('tool.json_schema.ne_bolshe'), $maximum));
            }
        }

        $maxLength = self::number($schema, 'maxLength');
        if (is_string($value) && is_int($maxLength) && mb_strlen($value) > $maxLength) {
            $errors[] = self::at($path, sprintf(t('tool.json_schema.ne_dlinnee_simvolov'), $maxLength));
        }

        if (!is_array($value)) {
            return;
        }

        $items = self::array($schema, 'items');
        if ($items !== null && array_is_list($value)) {
            foreach (array_keys($value) as $index) {
                self::check($items, $value[$index], sprintf('%s[%d]', $path, $index), $errors);
            }
        }

        $properties = self::array($schema, 'properties') ?? [];
        $required = self::array($schema, 'required') ?? [];
        foreach (array_keys($required) as $index) {
            $name = self::string($required, $index);
            if ($name !== null && !array_key_exists($name, $value)) {
                $errors[] = self::at(self::join($path, $name), t('tool.json_schema.obyazatelnoe_pole'));
            }
        }
        foreach (array_keys($value) as $name) {
            $name = (string) $name;
            $property = self::array($properties, $name);
            if ($property !== null) {
                self::check($property, $value[$name], self::join($path, $name), $errors);
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = self::at(self::join($path, $name), t('tool.json_schema.neizvestnoe_pole'));
            }
        }
    }

    /** @param array<array-key, mixed> $from */
    private static function string(array $from, int|string $key): ?string {
        return isset($from[$key]) && is_string($from[$key]) ? $from[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $from
     * @return array<array-key, mixed>|null
     */
    private static function array(array $from, int|string $key): ?array {
        return isset($from[$key]) && is_array($from[$key]) ? $from[$key] : null;
    }

    /** @param array<array-key, mixed> $from */
    private static function number(array $from, string $key): int|float|null {
        return isset($from[$key]) && (is_int($from[$key]) || is_float($from[$key])) ? $from[$key] : null;
    }

    private static function hasType(string $type, mixed $value): bool {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private static function show(mixed $value): string {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function join(string $path, string $name): string {
        return $path === '' ? $name : $path . '.' . $name;
    }

    private static function at(string $path, string $message): string {
        return $path === '' ? $message : $path . ': ' . $message;
    }
}
