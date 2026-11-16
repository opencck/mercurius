<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use InvalidArgumentException;

/**
 * What a participant may do: tools, run limits, sandbox limits, whether their runs may ask for approval.
 * No value may exceed the owner's.
 *
 * spec: BRIEF.md#права-участника
 */
final class ParticipantPolicy {
    private const RUN_KEYS = ['max_turns' => true, 'token_budget' => true, 'run_timeout' => true, 'tool_timeout' => true];
    private const SANDBOX_KEYS = ['disk_bytes' => true, 'processes' => true, 'cpu_seconds' => true, 'memory_bytes' => true, 'concurrent_commands' => true];

    /**
     * @param list<string>|null $tools allowed tool names; null — every tool
     */
    public function __construct(
        public readonly ?array $tools,
        public readonly RunLimits $run,
        public readonly ?SandboxLimits $sandbox,
        public readonly bool $mayRequestApproval,
        /** only calls that read run: the danger is judged per call, by its arguments (a POST is not a read) */
        public readonly bool $readOnly = false,
    ) {}

    public function allowsTool(string $name): bool {
        return $this->tools === null || in_array($name, $this->tools, true);
    }

    /** Every value at most the owner's: tools intersected, limits by minimum, approval only when both allow. */
    public function capTo(self $owner): self {
        $tools = match (true) {
            $owner->tools === null => $this->tools,
            $this->tools === null => $owner->tools,
            default => array_values(array_intersect($this->tools, $owner->tools)),
        };
        $sandbox = $owner->sandbox === null ? null : ($this->sandbox?->min($owner->sandbox) ?? $owner->sandbox);
        return new self($tools, $this->run->min($owner->run), $sandbox, $this->mayRequestApproval && $owner->mayRequestApproval);
    }

    /**
     * Applies stored overrides to the base policy of the role.
     *
     * @param array<array-key, mixed> $overrides
     * @throws InvalidArgumentException on unknown keys or bad values
     */
    public static function fromArray(array $overrides, self $base): self {
        $known = ['tools' => true, 'may_request_approval' => true, 'sandbox' => true] + self::RUN_KEYS;
        foreach (array_keys($overrides) as $key) {
            if (!isset($known[$key])) {
                throw new InvalidArgumentException(sprintf('Unknown policy key "%s"', $key));
            }
        }

        $tools = array_key_exists('tools', $overrides) ? self::tools($overrides['tools']) : $base->tools;
        $approval = $base->mayRequestApproval;
        if (array_key_exists('may_request_approval', $overrides)) {
            if (!is_bool($overrides['may_request_approval'])) {
                throw new InvalidArgumentException('may_request_approval must be a boolean');
            }
            $approval = $overrides['may_request_approval'];
        }
        $sandbox = array_key_exists('sandbox', $overrides) ? self::sandbox($overrides['sandbox'], $base->sandbox) : $base->sandbox;
        $run = new RunLimits(
            self::pick($overrides, 'max_turns', $base->run->maxTurns),
            self::pick($overrides, 'token_budget', $base->run->tokenBudget),
            self::pick($overrides, 'run_timeout', $base->run->runTimeout),
            self::pick($overrides, 'tool_timeout', $base->run->toolTimeout),
        );

        return new self($tools, $run, $sandbox, $approval);
    }

    /** @return array<string, mixed> every value, in the form fromArray() accepts */
    public function toArray(): array {
        $array = [
            'tools' => $this->tools,
            'max_turns' => $this->run->maxTurns,
            'token_budget' => $this->run->tokenBudget,
            'run_timeout' => $this->run->runTimeout,
            'tool_timeout' => $this->run->toolTimeout,
            'may_request_approval' => $this->mayRequestApproval,
        ];
        if ($this->sandbox !== null) {
            $array['sandbox'] = [
                'disk_bytes' => $this->sandbox->diskBytes,
                'processes' => $this->sandbox->processes,
                'cpu_seconds' => $this->sandbox->cpuSeconds,
                'memory_bytes' => $this->sandbox->memoryBytes,
                'concurrent_commands' => $this->sandbox->concurrentCommands,
            ];
        }
        return $array;
    }

    /** @return list<string>|null */
    private static function tools(mixed $value): ?array {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('tools must be a list of tool names or null');
        }
        $tools = [];
        foreach ($value as $name) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('tools must be a list of tool names or null');
            }
            $tools[] = $name;
        }
        return array_values(array_unique($tools));
    }

    private static function sandbox(mixed $value, ?SandboxLimits $base): SandboxLimits {
        if (!is_array($value)) {
            throw new InvalidArgumentException('sandbox must be an object');
        }
        if ($base === null) {
            throw new InvalidArgumentException('Sandbox limits are not configured');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !isset(self::SANDBOX_KEYS[$key])) {
                throw new InvalidArgumentException(sprintf('Unknown sandbox key "%s"', (string) $key));
            }
        }
        return new SandboxLimits(
            self::pick($value, 'disk_bytes', $base->diskBytes),
            self::pick($value, 'processes', $base->processes),
            self::pick($value, 'cpu_seconds', $base->cpuSeconds),
            self::pick($value, 'memory_bytes', $base->memoryBytes),
            self::pick($value, 'concurrent_commands', $base->concurrentCommands),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @param positive-int $default
     * @return positive-int
     */
    private static function pick(array $values, string $key, int $default): int {
        return array_key_exists($key, $values) ? self::positive($key, $values[$key]) : $default;
    }

    /** @return positive-int */
    private static function positive(string $key, mixed $value): int {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer', $key));
        }
        return $value;
    }
}
