<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Project;

use DateTimeImmutable;

/**
 * A task of a project: a tree of subtasks, a profile for choosing models, a result when closed, a deadline if set.
 *
 * spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту
 * spec: brief/scheduler.md#планировщик-и-проекты-1 — deadlines go to the daily summary
 */
final class ProjectTask {
    public const OPEN = 'open';
    public const DOING = 'doing';
    public const DONE = 'done';
    public const DROPPED = 'dropped';
    public const STATUSES = [self::OPEN, self::DOING, self::DONE, self::DROPPED];
    public const TITLE_MAX = 300;
    public const RESULT_MAX = 1000;

    /** @param array<string, int> $profile */
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly ?int $parentId,
        public readonly string $title,
        public readonly string $status,
        public readonly array $profile,
        public readonly ?string $result,
        public readonly ?DateTimeImmutable $dueAt = null,
    ) {}

    public function isOpen(): bool {
        return $this->status === self::OPEN || $this->status === self::DOING;
    }
}
