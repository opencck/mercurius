<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use JsonException;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;

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

            $tool = $policy->allowsTool($call->name) ? $this->registry->get($call->name) : null;
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
                    'Аргументы не разобраны как JSON: %s. Для инструмента без параметров передай {}.',
                    $e->getMessage(),
                ));
                continue;
            }
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                $result[] = $this->invalid($call, $tool, null, 'Аргументы должны быть JSON-объектом.');
                continue;
            }
            /** @var array<string, mixed> $args */
            $args = $decoded;

            $key = $call->name . "\0" . self::canonical($args);
            if (isset($seen[$key])) {
                $result[] = $this->invalid($call, $tool, $args, sprintf('Повтор вызова %s в этом же ответе: смотри его результат.', $seen[$key]));
                continue;
            }
            $seen[$key] = $id;

            $errors = JsonSchema::validate($tool->definition()->parameters, $args);
            if ($errors !== []) {
                $result[] = $this->invalid($call, $tool, $args, 'Аргументы не прошли проверку: ' . implode('; ', $errors) . '.');
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
            'Неизвестный инструмент «%s». %s',
            mb_substr($name, 0, 64),
            $names === [] ? 'Инструменты сейчас недоступны.' : 'Доступны: ' . implode(', ', $names) . '.',
        );
    }

    private static function canonical(mixed $value): string {
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
