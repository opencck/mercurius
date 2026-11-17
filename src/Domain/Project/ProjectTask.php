<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Project;

/**
 * A task of a project: a tree of subtasks, a profile for choosing models, a result when closed.
 *
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту
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
    ) {}

    public function isOpen(): bool {
        return $this->status === self::OPEN || $this->status === self::DOING;
    }
}
