<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Agent\Delegation;
use OpenCCK\Domain\Agent\DelegationTask;
use OpenCCK\Domain\Repository\DelegationRepository;

final class InMemoryDelegationRepository implements DelegationRepository {
    /** @var array<int, Delegation> */
    public array $delegations = [];
    /** @var array<int, array<int, DelegationTask>> delegation id => position => task */
    public array $tasks = [];
    private int $nextId = 1;
    /** finishTask throws: a store that fails in the middle of a delegation */
    public bool $failFinishTask = false;
    /** @var array<int, int> delegation id => deadline, unix seconds */
    private array $deadlines = [];
    /** @var array<int, true> */
    private array $stops = [];
    /** @var array<int, int> delegation id => run the report started */
    private array $deliveredRuns = [];

    public function create(int $conversationId, int $runId, int $principalId, string $callId, int $reserved, array $tasks, int $seconds = 1800): Delegation {
        $id = $this->nextId++;
        $this->deadlines[$id] = time() + $seconds;
        $this->delegations[$id] = new Delegation($id, $conversationId, $runId, $principalId, $callId, $reserved, Delegation::RUNNING);
        $this->tasks[$id] = [];
        foreach ($tasks as $i => $task) {
            $this->tasks[$id][$i + 1] = new DelegationTask($id, $i + 1, $task['task'], $task['share']);
        }
        return $this->view($this->delegations[$id]);
    }

    /** Moves the deadline, as if time passed. */
    public function expire(int $id): void {
        $this->deadlines[$id] = time() - 1;
    }

    private function view(Delegation $d): Delegation {
        return new Delegation(
            $d->id,
            $d->conversationId,
            $d->runId,
            $d->principalId,
            $d->callId,
            $d->reserved,
            $d->status,
            $d->report,
            isset($this->deliveredRuns[$d->id]),
            ($this->deadlines[$d->id] ?? 0) - time(),
            isset($this->stops[$d->id]),
        );
    }

    public function find(int $id): ?Delegation {
        return isset($this->delegations[$id]) ? $this->view($this->delegations[$id]) : null;
    }

    public function tasks(int $delegationId): array {
        $tasks = $this->tasks[$delegationId] ?? [];
        ksort($tasks);
        return array_values($tasks);
    }

    public function runningCount(int $principalId): int {
        return count(array_filter($this->running(), static fn(Delegation $d): bool => $d->principalId === $principalId));
    }

    public function running(?int $conversationId = null): array {
        return array_values(array_map($this->view(...), array_filter(
            $this->delegations,
            static fn(Delegation $d): bool => $d->status === Delegation::RUNNING && ($conversationId === null || $d->conversationId === $conversationId),
        )));
    }

    public function undelivered(): array {
        return array_values(array_map($this->view(...), array_filter(
            $this->delegations,
            fn(Delegation $d): bool => $d->status === Delegation::DONE && !isset($this->deliveredRuns[$d->id]),
        )));
    }

    public function setTaskRun(int $delegationId, int $position, int $attempt, int $runId): void {
        $t = $this->tasks[$delegationId][$position] ?? null;
        if ($t === null) {
            return;
        }
        $this->tasks[$delegationId][$position] = new DelegationTask(
            $t->delegationId,
            $t->position,
            $t->task,
            $t->share,
            $attempt === 1 ? $runId : $t->runId,
            $attempt === 2 ? $runId : $t->retryRunId,
            $t->report,
            $attempt === 3 ? $runId : $t->verifyRunId,
        );
    }

    public function finishTask(int $delegationId, int $position, array $report): void {
        if ($this->failFinishTask) {
            throw new \RuntimeException('store is down');
        }
        $t = $this->tasks[$delegationId][$position] ?? null;
        if ($t === null) {
            return;
        }
        $this->tasks[$delegationId][$position] = new DelegationTask($t->delegationId, $t->position, $t->task, $t->share, $t->runId, $t->retryRunId, $report, $t->verifyRunId);
    }

    public function finish(int $id, string $status, ?string $report): bool {
        $d = $this->delegations[$id] ?? null;
        if ($d === null || $d->status !== Delegation::RUNNING) {
            return false;
        }
        $this->delegations[$id] = new Delegation($d->id, $d->conversationId, $d->runId, $d->principalId, $d->callId, $d->reserved, $status, $report);
        return true;
    }

    public function requestStop(int $id): void {
        $this->stops[$id] = true;
    }

    public function markDelivered(int $id, int $runId): void {
        $this->deliveredRuns[$id] ??= $runId;
    }

    public function deliveredInto(int $runId): bool {
        return in_array($runId, $this->deliveredRuns, true);
    }

    public function ownsRun(int $runId): bool {
        foreach ($this->tasks as $tasks) {
            foreach ($tasks as $task) {
                if ($task->runId === $runId || $task->retryRunId === $runId || $task->verifyRunId === $runId) {
                    return true;
                }
            }
        }
        return false;
    }
}
