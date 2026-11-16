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
     */
    private function __construct(
        public readonly int $maxTurns,
        public readonly int $tokenBudget,
        public readonly int $runTimeout,
        public readonly int $toolTimeout,
        public readonly int $approvalTimeout,
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
        );
    }

    public function ownerLimits(): RunLimits {
        return new RunLimits($this->maxTurns, $this->tokenBudget, $this->runTimeout, $this->toolTimeout);
    }
}
