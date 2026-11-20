<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use InvalidArgumentException;

/**
 * What a participant may do: tools, run limits, sandbox limits, whether their runs may ask for approval.
 * No value may exceed the owner's.
 *
 * spec: brief/agent-loop.md#права-участника
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
        /** irreversible calls may run (by approval or a saved grant); a worker never has this right */
        public readonly bool $irreversible = true,
        /** USD a UTC day of this participant may cost; 0 — no cap of their own (spec: brief/participants.md#несколько-пользователей) */
        public readonly float $dailySpendUsd = 0.0,
    ) {}

    /** The same policy with one more tool; every tool already — the same. */
    /** @param list<string> $names tools taken out */
    public function without(array $names): self {
        if ($this->tools === null || array_intersect($this->tools, $names) === []) {
            return $this;
        }
        return new self(array_values(array_diff($this->tools, $names)), $this->run, $this->sandbox, $this->mayRequestApproval, $this->readOnly, $this->irreversible, $this->dailySpendUsd);
    }

    public function withTool(string $name): self {
        if ($this->tools === null || in_array($name, $this->tools, true) || $name === '') {
            return $this;
        }
        return new self([...$this->tools, $name], $this->run, $this->sandbox, $this->mayRequestApproval, $this->readOnly, $this->irreversible, $this->dailySpendUsd);
    }

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
        $cap = match (true) {
            $owner->dailySpendUsd <= 0.0 => $this->dailySpendUsd,
            $this->dailySpendUsd <= 0.0 => $owner->dailySpendUsd,
            default => min($this->dailySpendUsd, $owner->dailySpendUsd),
        };
        return new self(
            $tools,
            $this->run->min($owner->run),
            $sandbox,
            $this->mayRequestApproval && $owner->mayRequestApproval,
            readOnly: $this->readOnly || $owner->readOnly,
            irreversible: $this->irreversible && $owner->irreversible,
            dailySpendUsd: $cap,
        );
    }

    /**
     * Narrowed by another participant of a group: tools and limits as capTo. The right to ask for a card may be kept
     * when the run is the owner's: the card shows the owner the exact call, and nothing runs without the owner's press.
     *
     * spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — in a group the rights of the weakest active participant
     */
    public function narrowedBy(self $other, bool $keepApproval = false): self {
        $narrowed = $this->capTo($other);
        if (!$keepApproval || !$this->mayRequestApproval || $narrowed->mayRequestApproval) {
            return $narrowed;
        }
        return new self($narrowed->tools, $narrowed->run, $narrowed->sandbox, true, $narrowed->readOnly, $narrowed->irreversible, $narrowed->dailySpendUsd);
    }

    /**
     * Applies stored overrides to the base policy of the role.
     *
     * @param array<array-key, mixed> $overrides
     * @throws InvalidArgumentException on unknown keys or bad values
     */
    public static function fromArray(array $overrides, self $base): self {
        $known = ['tools' => true, 'may_request_approval' => true, 'sandbox' => true, 'daily_spend_usd' => true] + self::RUN_KEYS;
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

        $cap = $base->dailySpendUsd;
        if (array_key_exists('daily_spend_usd', $overrides)) {
            $value = $overrides['daily_spend_usd'];
            if (!(is_int($value) || is_float($value)) || $value < 0 || $value > 10_000) {
                throw new InvalidArgumentException('daily_spend_usd must be a number of USD from 0 (no cap) to 10000');
            }
            $cap = (float) $value;
        }

        return new self($tools, $run, $sandbox, $approval, dailySpendUsd: $cap);
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
            'daily_spend_usd' => $this->dailySpendUsd,
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
     * @param non-negative-int $default the base; 0 only for the address space, where it is no limit
     * @return non-negative-int
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
