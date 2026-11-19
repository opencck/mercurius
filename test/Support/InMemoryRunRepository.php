<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Repository\RunRepository;

final class InMemoryRunRepository implements RunRepository {
    /** @var array<int, Run> */
    public array $runs = [];
    private int $next = 1;

    public function start(int $conversationId, int $principalId, string $model, RunLimits $limits, ?int $parentRunId = null, ?string $outside = null): ?Run {
        if ($this->active($conversationId) !== null) {
            return null;
        }
        $run = new Run($this->next++, $conversationId, $principalId, $parentRunId, $model, RunStatus::Running, null, $limits, outside: $outside);
        return $this->runs[$run->id] = $run;
    }

    public function find(int $id): ?Run {
        return $this->runs[$id] ?? null;
    }

    public function active(int $conversationId): ?Run {
        foreach ($this->runs as $run) {
            if ($run->conversationId === $conversationId && $run->status->isActive()) {
                return $run;
            }
        }
        return null;
    }

    public function unfinished(): array {
        return array_values(array_filter($this->runs, static fn(Run $r): bool => $r->status === RunStatus::Running));
    }

    public function reserve(int $runId, int $tokens): bool {
        $run = $this->runs[$runId];
        if ($run->tokensUsed + $run->tokensReserved + $tokens > $run->limits->tokenBudget) {
            return false;
        }
        $this->runs[$runId] = $this->with($run, tokensReserved: $run->tokensReserved + $tokens);
        return true;
    }

    public function settle(int $runId, int $reserved, int $actual, int $promptAnchor): void {
        $run = $this->runs[$runId];
        $this->runs[$runId] = $this->with(
            $run,
            tokensReserved: max(0, $run->tokensReserved - $reserved),
            tokensUsed: $run->tokensUsed + $actual,
            turns: $run->turns + 1,
            promptAnchor: $promptAnchor,
        );
    }

    public function settleAuxiliary(int $runId, int $reserved, int $actual): void {
        $run = $this->runs[$runId];
        $this->runs[$runId] = $this->with(
            $run,
            tokensReserved: max(0, $run->tokensReserved - $reserved),
            tokensUsed: $run->tokensUsed + $actual,
            promptAnchor: 0,
        );
    }

    public function addActiveTime(int $runId, int $ms): void {
        $this->runs[$runId] = $this->with($this->runs[$runId], activeMs: $this->runs[$runId]->activeMs + $ms);
    }

    public function markOutside(int $runId, string $source): void {
        $outside = $this->runs[$runId]->outside;
        if ($outside === null || !in_array($source, explode(', ', $outside), true)) {
            $this->runs[$runId] = $this->with($this->runs[$runId], outside: $outside === null ? $source : $outside . ', ' . $source);
        }
    }

    public function switchModel(int $runId, string $model): void {
        $this->runs[$runId] = $this->with($this->runs[$runId], fallbackModel: $model);
    }

    public function saveLoopHistory(int $runId, array $history): void {
        $this->runs[$runId] = $this->with($this->runs[$runId], loopHistory: $history);
    }

    public function markWarned(int $runId): void {
        $this->runs[$runId] = $this->with($this->runs[$runId], warned: true);
    }

    public function finish(int $runId, RunStatus $status, ?StopReason $reason): void {
        $this->runs[$runId] = $this->with($this->runs[$runId], status: $status, stopReason: $reason);
    }

    public function setStatus(int $runId, RunStatus $status): void {
        $this->runs[$runId] = $this->with($this->runs[$runId], status: $status);
    }

    public function transition(int $runId, RunStatus $from, RunStatus $to): ?Run {
        $run = $this->runs[$runId] ?? null;
        if ($run === null || $run->status !== $from) {
            return null;
        }
        return $this->runs[$runId] = $this->with($run, status: $to);
    }

    public function childCount(int $parentRunId): int {
        return count(array_filter($this->runs, static fn(Run $r): bool => $r->parentRunId === $parentRunId));
    }

    public function waitingApproval(): array {
        return array_values(array_filter($this->runs, static fn(Run $r): bool => $r->status === RunStatus::WaitingApproval));
    }

    /** @param list<string>|null $loopHistory */
    private function with(
        Run $run,
        ?RunStatus $status = null,
        ?StopReason $stopReason = null,
        ?int $turns = null,
        ?int $tokensUsed = null,
        ?int $tokensReserved = null,
        ?int $activeMs = null,
        ?int $promptAnchor = null,
        ?bool $warned = null,
        ?string $outside = null,
        ?string $fallbackModel = null,
        ?array $loopHistory = null,
    ): Run {
        return new Run(
            $run->id,
            $run->conversationId,
            $run->principalId,
            $run->parentRunId,
            $run->model,
            $status ?? $run->status,
            $stopReason ?? $run->stopReason,
            $run->limits,
            $turns ?? $run->turns,
            $tokensUsed ?? $run->tokensUsed,
            $tokensReserved ?? $run->tokensReserved,
            $activeMs ?? $run->activeMs,
            $promptAnchor ?? $run->promptAnchor,
            $warned ?? $run->warned,
            $outside ?? $run->outside,
            $fallbackModel ?? $run->fallbackModel,
            $loopHistory ?? $run->loopHistory,
        );
    }
}
