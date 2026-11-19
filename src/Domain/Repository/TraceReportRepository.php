<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * Read-only figures for the owner's reports: spend by model and day, recent and costliest runs.
 *
 * spec: BRIEF.md#трассировка-и-метрики — /usage, /runs
 */
interface TraceReportRepository {
    /** @return list<array{model: string, calls: int, input: int, output: int, cost: float}> costliest first */
    public function usageByModel(DateTimeImmutable $since): array;

    /**
     * Spend split as the query says, the costliest first. chat — the group's title (null for a private chat), chatId
     * and thread — where, project — the project of that topic or chat, person — the participant, label — the model or
     * the day.
     *
     * @return list<array{chat: ?string, chatId: ?string, thread: string, project: ?string, person: ?string, label: string, calls: int, input: int, output: int, cost: float}>
     */
    public function breakdown(\OpenCCK\Domain\Agent\SpendQuery $query): array;

    /** @return list<array{day: string, calls: int, cost: float}> newest first */
    public function usageByDay(DateTimeImmutable $since): array;

    /** @return list<array{id: int, cost: float, tokens: int}> */
    public function costliestRuns(DateTimeImmutable $since, int $limit): array;

    /** @return list<array{id: int, status: string, reason: ?string, model: string, turns: int, tokens: int, active_ms: int, cost: float, started: string}> newest first */
    public function recentRuns(int $limit): array;

    /**
     * Why the model of a run was chosen: the decisions taken for it, oldest first.
     *
     * @return list<array{method: string, class: string, model: string, reason: string}>
     */
    public function decisions(int $runId): array;

    /**
     * How the agent delegated: top-level runs and those that delegated, worker runs, cascade retries, verifiers and
     * their rejections, background delegations.
     *
     * spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс — /delegation
     *
     * @return array{top: int, delegating: int, workers: int, cascades: int, verifiers: int, rejected: int, background: int}
     */
    public function delegation(DateTimeImmutable $since): array;

    /** @return list<array{model: string, runs: int, answered: int, failed: int, tokens: int, cost: float, active_ms: int}> worker runs by model, most used first */
    public function workerModels(DateTimeImmutable $since): array;

    /**
     * Spend by the version of the system prompt of the conversation a call was made in: runs, calls and cost; null —
     * a conversation opened before versions were kept, or a call without one.
     *
     * spec: BRIEF.md#трассировка-и-метрики — /usage by prompt_version
     *
     * @return list<array{version: ?int, runs: int, calls: int, cost: float}> newest version first
     */
    public function usageByPromptVersion(DateTimeImmutable $since): array;

    /**
     * Delegation by the version of the orchestrator's system prompt: its top-level runs, those that delegated, their
     * worker runs (retries and verifiers too) and what the workers cost.
     *
     * spec: BRIEF.md#трассировка-и-метрики — /delegation by prompt_version
     *
     * @return list<array{version: ?int, top: int, delegating: int, workers: int, cost: float}> newest version first
     */
    public function delegationByPromptVersion(DateTimeImmutable $since): array;
}
