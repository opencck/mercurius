<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Repository\TraceReportRepository;

/** Fixed report rows; records the period asked for. */
final class FakeTraceReports implements TraceReportRepository {
    public ?DateTimeImmutable $since = null;

    /**
     * @param list<array{model: string, calls: int, input: int, output: int, cost: float}> $models
     * @param list<array{day: string, calls: int, cost: float}> $days
     * @param list<array{id: int, cost: float, tokens: int}> $costliest
     * @param list<array{id: int, status: string, reason: ?string, model: string, turns: int, tokens: int, active_ms: int, cost: float, started: string, helpers?: list<array{model: string, tokens: int, cost: float}>}> $runs
     */
    public function __construct(
        public array $models = [],
        public array $days = [],
        public array $costliest = [],
        public array $runs = [],
        /** @var array{top: int, delegating: int, workers: int, cascades: int, verifiers: int, rejected: int, background: int} */
        public array $delegation = ['top' => 0, 'delegating' => 0, 'workers' => 0, 'cascades' => 0, 'verifiers' => 0, 'rejected' => 0, 'background' => 0],
        /** @var list<array{model: string, runs: int, answered: int, failed: int, tokens: int, cost: float, active_ms: int}> */
        public array $workerModels = [],
    ) {}

    public function delegation(DateTimeImmutable $since): array {
        $this->since = $since;
        return $this->delegation;
    }

    public function workerModels(DateTimeImmutable $since): array {
        return $this->workerModels;
    }

    /** @var list<array{name: string, calls: int, errors: int, avg_ms: int}> */
    public array $tools = [];

    /** @var list<array{run: int, kind: string, name: string, ms: int}> */
    public array $longest = [];

    /** @var array{orchestrator: int, workers: int} */
    public array $delegationTokens = ['orchestrator' => 0, 'workers' => 0];

    public function toolsUsage(DateTimeImmutable $since): array {
        return $this->tools;
    }

    public function longestSteps(DateTimeImmutable $since, int $limit): array {
        return array_slice($this->longest, 0, $limit);
    }

    public function delegationTokens(DateTimeImmutable $since): array {
        return $this->delegationTokens;
    }

    /** @var list<array{version: ?int, runs: int, calls: int, cost: float}> */
    public array $usageVersions = [];

    /** @var list<array{version: ?int, top: int, delegating: int, workers: int, cost: float}> */
    public array $delegationVersions = [];

    public function usageByPromptVersion(DateTimeImmutable $since): array {
        return $this->usageVersions;
    }

    public function delegationByPromptVersion(DateTimeImmutable $since): array {
        return $this->delegationVersions;
    }

    /** @var list<array{chat: ?string, chatId: ?string, thread: string, project: ?string, person: ?string, label: string, calls: int, input: int, output: int, cost: float}> */
    public array $breakdown = [];
    public ?\OpenCCK\Domain\Agent\SpendQuery $lastQuery = null;

    public function breakdown(\OpenCCK\Domain\Agent\SpendQuery $query): array {
        $this->lastQuery = $query;
        return $this->breakdown;
    }

    public function usageByModel(DateTimeImmutable $since): array {
        $this->since = $since;
        return $this->models;
    }

    public function usageByDay(DateTimeImmutable $since): array {
        return $this->days;
    }

    public function costliestRuns(DateTimeImmutable $since, int $limit): array {
        return array_slice($this->costliest, 0, $limit);
    }

    /** @var array<int, list<array{method: string, class: string, model: string, reason: string}>> */
    public array $decisions = [];

    public function decisions(int $runId): array {
        return $this->decisions[$runId] ?? [];
    }

    /** @var array{0?: int, 1?: ?string} the count and status /runs asked for */
    public array $runsAsked = [];

    /** @var list<int> the runs asked for by number */
    public array $idsAsked = [];

    public function recentRuns(int $limit, ?string $status = null, array $ids = []): array {
        $this->runsAsked = [$limit, $status];
        $this->idsAsked = $ids;
        $rows = $ids === [] ? $this->runs : array_values(array_filter($this->runs, static fn(array $r): bool => in_array($r['id'], $ids, true)));
        return array_slice($rows, 0, $limit);
    }
}
