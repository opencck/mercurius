<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Which projects sleep: their facts are checked and added to by the model of sleep at night. None by default.
 *
 * spec: brief/memory.md#сон-проекта
 */
interface ProjectSleepRepository {
    public function on(int $projectId): bool;

    public function set(int $projectId, bool $on): void;

    /** @return list<int> the projects that sleep */
    public function sleeping(): array;
}
