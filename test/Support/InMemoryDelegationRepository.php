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

    public function create(int $conversationId, int $runId, int $principalId, string $callId, int $reserved, array $tasks): Delegation {
        $id = $this->nextId++;
        $this->delegations[$id] = new Delegation($id, $conversationId, $runId, $principalId, $callId, $reserved, Delegation::RUNNING);
        $this->tasks[$id] = [];
        foreach ($tasks as $i => $task) {
            $this->tasks[$id][$i + 1] = new DelegationTask($id, $i + 1, $task['task'], $task['share']);
        }
        return $this->delegations[$id];
    }

    public function find(int $id): ?Delegation {
        return $this->delegations[$id] ?? null;
    }

    public function tasks(int $delegationId): array {
        $tasks = $this->tasks[$delegationId] ?? [];
        ksort($tasks);
        return array_values($tasks);
    }

    public function runningCount(int $conversationId): int {
        return count($this->running($conversationId));
    }

    public function running(?int $conversationId = null): array {
        return array_values(array_filter(
            $this->delegations,
            static fn(Delegation $d): bool => $d->status === Delegation::RUNNING && ($conversationId === null || $d->conversationId === $conversationId),
        ));
    }

    public function undelivered(): array {
        return array_values(array_filter($this->delegations, static fn(Delegation $d): bool => $d->status === Delegation::DONE && !$d->delivered));
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
        );
    }

    public function finishTask(int $delegationId, int $position, array $report): void {
        $t = $this->tasks[$delegationId][$position] ?? null;
        if ($t === null) {
            return;
        }
        $this->tasks[$delegationId][$position] = new DelegationTask($t->delegationId, $t->position, $t->task, $t->share, $t->runId, $t->retryRunId, $report);
    }

    public function finish(int $id, string $status, ?string $report): bool {
        $d = $this->delegations[$id] ?? null;
        if ($d === null || $d->status !== Delegation::RUNNING) {
            return false;
        }
        $this->delegations[$id] = new Delegation($d->id, $d->conversationId, $d->runId, $d->principalId, $d->callId, $d->reserved, $status, $report);
        return true;
    }

    public function markDelivered(int $id): void {
        $d = $this->delegations[$id] ?? null;
        if ($d !== null) {
            $this->delegations[$id] = new Delegation($d->id, $d->conversationId, $d->runId, $d->principalId, $d->callId, $d->reserved, $d->status, $d->report, true);
        }
    }

    public function ownsRun(int $runId): bool {
        foreach ($this->tasks as $tasks) {
            foreach ($tasks as $task) {
                if ($task->runId === $runId || $task->retryRunId === $runId) {
                    return true;
                }
            }
        }
        return false;
    }
}
