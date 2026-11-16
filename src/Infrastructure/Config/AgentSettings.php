<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use OpenCCK\Domain\Agent\RunLimits;

/**
 * Limits of one agent run. They are the owner's limits and the ceiling for every other participant.
 *
 * spec: BRIEF.md#лимиты — defaults and what each limit bounds
 */
final class AgentSettings {
    /**
     * @param positive-int $maxTurns model calls per run
     * @param positive-int $tokenBudget input + output tokens per run
     * @param positive-int $runTimeout seconds per run, approval waits excluded
     * @param positive-int $toolTimeout seconds per tool call, at most 600
     * @param positive-int $approvalTimeout seconds the owner has to answer an approval card; silence is a denial
     * @param positive-int $workerMaxTurns model calls of one worker
     * @param positive-int $workerTokenBudget tokens of one worker at most, taken from the orchestrator's budget
     * @param positive-int $maxWorkersPerRun worker runs (retries and verifiers included) one run may start
     * @param float $dailySpendCap USD a UTC day may cost over all runs; 0 — no cap
     * @param float $monthlySpendCap USD a UTC month may cost; 0 — no cap
     */
    private function __construct(
        public readonly int $maxTurns,
        public readonly int $tokenBudget,
        public readonly int $runTimeout,
        public readonly int $toolTimeout,
        public readonly int $approvalTimeout,
        public readonly int $workerMaxTurns,
        public readonly int $workerTokenBudget,
        public readonly int $maxWorkersPerRun = 10,
        public readonly float $dailySpendCap = 0.0,
        public readonly float $monthlySpendCap = 0.0,
    ) {}

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        return new self(
            maxTurns: Env::positiveInt($env, 'AGENT_MAX_TURNS', 30, 10_000),
            tokenBudget: Env::positiveInt($env, 'AGENT_TOKEN_BUDGET', 400_000, 50_000_000),
            runTimeout: Env::positiveInt($env, 'AGENT_RUN_TIMEOUT', 900, 86_400),
            toolTimeout: Env::positiveInt($env, 'AGENT_TOOL_TIMEOUT', 120, 600),
            approvalTimeout: Env::positiveInt($env, 'AGENT_APPROVAL_TIMEOUT', 1800, 86_400),
            workerMaxTurns: Env::positiveInt($env, 'AGENT_WORKER_MAX_TURNS', 15, 100),
            workerTokenBudget: Env::positiveInt($env, 'AGENT_WORKER_TOKEN_BUDGET', 100_000, 10_000_000),
            maxWorkersPerRun: Env::positiveInt($env, 'AGENT_MAX_WORKERS_PER_RUN', 10, 100),
            dailySpendCap: self::usd($env, 'AGENT_DAILY_SPEND_CAP'),
            monthlySpendCap: self::usd($env, 'AGENT_MONTHLY_SPEND_CAP'),
        );
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    private static function usd(array $env, string $name): float {
        $value = Env::raw($env, $name);
        if ($value === null) {
            return 0.0;
        }
        if (!is_numeric($value) || (float) $value < 0.0) {
            throw ConfigException::invalid($name, 'a sum in USD, 0 or more');
        }
        return (float) $value;
    }

    public function ownerLimits(): RunLimits {
        return new RunLimits($this->maxTurns, $this->tokenBudget, $this->runTimeout, $this->toolTimeout);
    }
}
