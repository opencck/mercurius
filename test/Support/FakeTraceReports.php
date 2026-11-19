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
     * @param list<array{id: int, status: string, reason: ?string, model: string, turns: int, tokens: int, active_ms: int, cost: float, started: string}> $runs
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

    public function recentRuns(int $limit): array {
        return array_slice($this->runs, 0, $limit);
    }
}
