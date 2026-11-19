<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;

interface ModelRoleRepository {
    /** @return array<string, ModelRole> role => row */
    public function all(): array;

    /**
     * Sets the model of a role; false when the owner fixed the role and the writer is not the owner. The owner may
     * write without fixing, which takes the fix off.
     */
    public function set(string $role, ModelRef $model, string $reason, string $setBy, ?int $principalId = null, bool $byOwner = false): bool;

    /**
     * Adds the roles that have no row yet, marked as seed; existing rows are kept.
     *
     * @param array<string, ModelRef> $models role => model
     */
    public function seed(array $models, string $reason): void;

    /**
     * The changes of the models of roles, newest first: every successful set is one record.
     *
     * spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the history of model roles with a rollback
     *
     * @return list<array{id: int, role: string, model: ModelRef, reason: string, setBy: string, by: ?int, at: int}>
     */
    public function history(int $limit): array;
}
