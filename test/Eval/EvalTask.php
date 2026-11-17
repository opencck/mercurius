<?php

declare(strict_types=1);

namespace OpenCCK\Test\Eval;

use InvalidArgumentException;

/**
 * One task of the evaluation set: the request, the tools the agent gets (fakes with fixed results), checks made by
 * code and a rubric for the judge.
 *
 * spec: BRIEF.md#границы-самоулучшения — a change is accepted when the set shows no regression in any category
 */
final class EvalTask {
    /**
     * @param list<array{name: string, description: string, danger: string, parameters: array<string, mixed>, result: string, error: bool, untrusted: bool}> $tools
     * @param list<string> $called tools that must be called
     * @param list<string> $notCalled tools that must not be called
     * @param list<string> $answerAny at least one of these is in the answer (case-insensitive)
     * @param list<string> $answerNone none of these is in the answer (case-insensitive)
     * @param array<string, string> $argsContain tool => a substring of its arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $prompt,
        public readonly array $tools,
        public readonly array $called,
        public readonly array $notCalled,
        public readonly array $answerAny,
        public readonly array $answerNone,
        public readonly array $argsContain,
        public readonly string $rubric,
    ) {}

    /** @return list<self> */
    public static function load(string $path): array {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new InvalidArgumentException('Cannot read ' . $path);
        }
        /** @var mixed $data */
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['tasks']) || !is_array($data['tasks'])) {
            throw new InvalidArgumentException('No tasks in ' . $path);
        }
        $tasks = [];
        foreach ($data['tasks'] as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('A task is not an object');
            }
            $tasks[] = self::fromArray($raw);
        }
        return $tasks;
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self {
        $tools = [];
        foreach (self::list($raw, 'tools') as $tool) {
            if (!is_array($tool)) {
                throw new InvalidArgumentException('A tool is not an object');
            }
            /** @var array<string, mixed> $parameters */
            $parameters = isset($tool['parameters']) && is_array($tool['parameters']) ? $tool['parameters'] : ['type' => 'object', 'properties' => new \stdClass()];
            $tools[] = [
                'name' => self::str($tool, 'name'),
                'description' => self::str($tool, 'description'),
                'danger' => isset($tool['danger']) && is_string($tool['danger']) ? $tool['danger'] : 'read',
                'parameters' => $parameters,
                'result' => self::str($tool, 'result'),
                'error' => ($tool['error'] ?? false) === true,
                'untrusted' => ($tool['untrusted'] ?? false) === true,
            ];
        }
        $checks = isset($raw['checks']) && is_array($raw['checks']) ? $raw['checks'] : [];
        $argsContain = [];
        if (isset($checks['args_contain']) && is_array($checks['args_contain'])) {
            foreach (array_keys($checks['args_contain']) as $tool) {
                if (is_string($tool) && is_string($checks['args_contain'][$tool])) {
                    $argsContain[$tool] = $checks['args_contain'][$tool];
                }
            }
        }
        return new self(
            self::str($raw, 'id'),
            self::str($raw, 'category'),
            self::str($raw, 'prompt'),
            $tools,
            self::strings($checks, 'called'),
            self::strings($checks, 'not_called'),
            self::strings($checks, 'answer_any'),
            self::strings($checks, 'answer_none'),
            $argsContain,
            isset($raw['rubric']) && is_string($raw['rubric']) ? $raw['rubric'] : '',
        );
    }

    /** @param array<array-key, mixed> $raw */
    private static function str(array $raw, string $key): string {
        if (!isset($raw[$key]) || !is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidArgumentException('Missing ' . $key);
        }
        return $raw[$key];
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return list<mixed>
     */
    private static function list(array $raw, string $key): array {
        return isset($raw[$key]) && is_array($raw[$key]) ? array_values($raw[$key]) : [];
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return list<string>
     */
    private static function strings(array $raw, string $key): array {
        $found = [];
        /** @var mixed $item */
        foreach (self::list($raw, $key) as $item) {
            if (is_string($item)) {
                $found[] = $item;
            }
        }
        return $found;
    }
}
