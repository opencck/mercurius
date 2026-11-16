<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;

interface ModelRoleRepository {
    /** @return array<string, ModelRole> role => row */
    public function all(): array;

    /** Sets the model of a role; false when the owner fixed the role and $setBy is not the owner. */
    public function set(string $role, ModelRef $model, string $reason, string $setBy, ?int $principalId = null): bool;

    /**
     * Adds the roles that have no row yet, marked as seed; existing rows are kept.
     *
     * @param array<string, ModelRef> $models role => model
     */
    public function seed(array $models, string $reason): void;
}
