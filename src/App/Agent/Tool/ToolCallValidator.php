<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use JsonException;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;

use function Mercurius\I18n\t;

/**
 * Checks tool calls of one model answer before anything runs.
 *
 * spec: BRIEF.md#запуск-и-цикл — validation before execution
 * ref: references/hermes-agent/agent/turn_tool_validation.py@e96221f:L97-L269 (validate_tool_calls) — unique ids,
 *      empty arguments as {}, error results with the allowed names, mixed batches run their valid calls, arguments
 *      that do not parse and do not end with } or ] count as cut off whatever finish_reason says. We do not repair
 *      misspelled names and do not silently retry broken JSON: the model gets the error as the call's result.
 * ref: references/hermes-agent/run_agent.py@e96221f:L1216-L1233 (_deduplicate_tool_calls) — duplicates by name and
 *      canonical arguments; hermes drops them, we keep them with a stub result so every saved call has a result.
 */
final class ToolCallValidator {
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param list<ToolCall> $calls
     * @param list<string> $takenIds call ids already used earlier in the run
     */
    public function validate(array $calls, ParticipantPolicy $policy, array $takenIds = []): ValidationOutcome {
        $taken = array_fill_keys($takenIds, true);
        $seen = [];
        $truncated = false;
        $result = [];

        foreach ($calls as $call) {
            $id = $this->uniqueId($call->id, $taken);
            $json = trim($call->argumentsJson) === '' ? '{}' : $call->argumentsJson;
            $call = new ToolCall($id, $call->name, $json);

            $tool = $this->registry->allows($policy, $call->name) ? $this->registry->get($call->name) : null;
            if ($tool === null) {
                $result[] = $this->invalid($call, null, null, $this->unknown($call->name, $policy));
                continue;
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                if (!str_ends_with(rtrim($json), '}') && !str_ends_with(rtrim($json), ']')) {
                    $truncated = true;
                }
                $result[] = $this->invalid($call, $tool, null, sprintf(
                    t('tool.tool_call_validator.argumenty_ne_razobrany_kak_json'),
                    $e->getMessage(),
                ));
                continue;
            }
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                $result[] = $this->invalid($call, $tool, null, t('tool.tool_call_validator.argumenty_dolzhny_byt_json_obektom'));
                continue;
            }
            /** @var array<string, mixed> $args */
            $args = self::coerce($decoded, $tool->definition()->parameters);

            $key = $call->name . "\0" . CanonicalJson::encode($args);
            if (isset($seen[$key])) {
                $result[] = $this->invalid($call, $tool, $args, sprintf(t('tool.tool_call_validator.povtor_vyzova_v_etom_zhe'), $seen[$key]));
                continue;
            }
            $seen[$key] = $id;

            $errors = JsonSchema::validate($tool->definition()->parameters, $args);
            if ($errors !== []) {
                $result[] = $this->invalid($call, $tool, $args, t('tool.tool_call_validator.argumenty_ne_proshli_proverku') . implode('; ', $errors) . '.');
                continue;
            }

            $result[] = new ValidatedCall($call, $tool, $args, null, $tool->danger($args));
        }

        return new ValidationOutcome($result, $truncated);
    }

    /** @param array<string, true> $taken */
    private function uniqueId(string $id, array &$taken): string {
        $id = $id === '' ? 'call' : $id;
        $unique = $id;
        for ($n = 2; isset($taken[$unique]); $n++) {
            $unique = $id . '-' . $n;
        }
        $taken[$unique] = true;
        return $unique;
    }

    /** @param array<string, mixed>|null $args */
    private function invalid(ToolCall $call, ?Tool $tool, ?array $args, string $error): ValidatedCall {
        return new ValidatedCall($call, $tool, $args, $error, DangerClass::Read);
    }

    private function unknown(string $name, ParticipantPolicy $policy): string {
        $names = $this->registry->names($policy);
        return sprintf(
            t('tool.tool_call_validator.neizvestnyy_instrument'),
            mb_substr($name, 0, 64),
            $names === [] ? t('tool.tool_call_validator.instrumenty_seychas_nedostupny') : t('tool.tool_call_validator.dostupny') . implode(', ', $names) . '.',
        );
    }


    /**
     * Strings of simple types read as the schema says: "42" for an integer, "true" for a boolean, a JSON text for an
     * array or an object. What does not parse stays as it was, and the schema check speaks about it.
     *
     * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — arguments of simple types are brought to the schema first
     * ref: references/hermes-agent/tools/arg_coercion.py@e96221fa:L173-L240 (_coerce_value) — the same rules: a number
     *      that is not finite or an integer with decimals stays a string, booleans by "true"/"false"
     * @param mixed $args the decoded arguments, an object
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>
     */
    private static function coerce(mixed $args, array $parameters): array {
        if (!is_array($args)) {
            return [];
        }
        $properties = $parameters['properties'] ?? null;
        if (!is_array($properties)) {
            return $args;
        }
        foreach ($args as $key => $value) {
            $schema = $properties[$key] ?? null;
            if (!is_string($value) || !is_array($schema) || !isset($schema['type']) || !is_string($schema['type'])) {
                continue;
            }
            $args[$key] = match ($schema['type']) {
                'integer' => preg_match('~^\s*-?\d{1,18}(\.0+)?\s*$~', $value) === 1 ? (int) $value : $value,
                'number' => is_numeric($value) && is_finite((float) $value) ? (str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value) : $value,
                'boolean' => match (strtolower(trim($value))) {
                    'true' => true,
                    'false' => false,
                    default => $value,
                },
                'array' => self::json($value, true) ?? $value,
                'object' => self::json($value, false) ?? $value,
                default => $value,
            };
        }
        return $args;
    }

    /** @return array<array-key, mixed>|null the JSON text as a list (or an object); null when it is not one */
    private static function json(string $value, bool $list): ?array {
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        return array_is_list($decoded) === $list || $decoded === [] ? $decoded : null;
    }
}
