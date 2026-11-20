<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use OpenCCK\App\Agent\Tool\JsonSchema;

use function Mercurius\I18n\t;

/**
 * The answer schema of a part (`output_schema`): the worker gets it as a contract with its task, its final answer is
 * checked against it, and a wrong one gets exactly one correction turn with the errors.
 *
 * spec: brief/orchestration.md#схема-ответа-воркера
 * ref: references/hermes-agent/tools/delegation_output_schema.py@e96221fa:L45-L116 (append_output_contract,
 *      extract_json_candidate, validate_output, build_retry_message) — the contract block, the JSON taken out of a
 *      fence or prose, ten errors at most, one retry with the errors verbatim and the contract again; the schema is
 *      checked by our subset of JSON Schema (the one tool arguments use), not a full validator
 */
final class OutputContract {
    public const MAX_SCHEMA_BYTES = 8000;
    private const MAX_ERRORS = 10;

    /**
     * A usable schema, or why it is not one.
     *
     * @return array<array-key, mixed>|string
     */
    public static function coerce(mixed $raw): array|string {
        if (is_string($raw)) {
            // models sometimes send the schema as a JSON string
            try {
                /** @var mixed $raw */
                $raw = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return t('delegation.output_contract.not_an_object');
            }
        }
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            return t('delegation.output_contract.not_an_object');
        }
        $json = json_encode($raw, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_SCHEMA_BYTES) {
            return sprintf(t('delegation.output_contract.too_big'), self::MAX_SCHEMA_BYTES);
        }
        return $raw;
    }

    /** @param array<array-key, mixed> $schema */
    public static function block(array $schema): string {
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return t('delegation.output_contract.block') . "\n" . ($json === false ? '{}' : $json);
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return list<string> what is wrong with the answer, empty when it holds
     */
    public static function check(string $text, array $schema): array {
        $candidate = self::candidate($text);
        if (trim($candidate) === '') {
            return [t('delegation.output_contract.empty')];
        }
        try {
            /** @var mixed $value */
            $value = json_decode($candidate, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [sprintf(t('delegation.output_contract.not_json'), $e->getMessage())];
        }
        return array_slice(JsonSchema::validate($schema, $value), 0, self::MAX_ERRORS);
    }

    /**
     * The correction turn: the errors verbatim, then the contract again (a compaction may have left the first one
     * only in a summary).
     *
     * @param list<string> $errors
     * @param array<array-key, mixed> $schema
     */
    public static function retry(array $errors, array $schema): string {
        return t('delegation.output_contract.rejected') . "\n" . implode("\n", array_map(static fn(string $e): string => '- ' . $e, $errors))
            . "\n\n" . t('delegation.output_contract.reply_only') . "\n\n" . self::block($schema);
    }

    /** The JSON in the answer: out of a code fence, then the outermost object or list that parses. */
    public static function candidate(string $text): string {
        $raw = trim($text);
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $raw);
            $raw = trim((string) preg_replace('/\s*```\s*$/', '', $raw));
        }
        $spans = [];
        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($raw, $open);
            $end = strrpos($raw, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $spans[$start] = substr($raw, $start, $end - $start + 1);
            }
        }
        ksort($spans);
        foreach ($spans as $span) {
            try {
                json_decode($span, true, 64, JSON_THROW_ON_ERROR);
                return $span;
            } catch (\JsonException) {
                continue;
            }
        }
        return $spans === [] ? $raw : reset($spans);
    }
}
