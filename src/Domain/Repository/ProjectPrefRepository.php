<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * A project's own time zone and quiet hours. Null — the defaults of the environment; quiet hours '' — none.
 *
 * spec: brief/scheduler.md#часовой-пояс-проекта
 */
interface ProjectPrefRepository {
    /** @return array{timezone: ?string, quiet: ?string} */
    public function get(int $projectId): array;

    public function save(int $projectId, ?string $timezone, ?string $quiet): void;
}
