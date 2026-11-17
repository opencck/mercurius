<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;

/**
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — projects, their tasks, each participant's relation
 *       to a project, and the project a chat works on
 */
interface ProjectRepository {
    /**
     * @param int|null $ownerId null — the agent's owner; otherwise the participant
     * @throws DomainException when this owner has a project with this name (compared case-insensitively)
     */
    public function create(string $name, string $goal, ?int $ownerId = null): Project;

    public function find(int $id): ?Project;

    /** @param int|null $for null — the owner: every project, their own first; otherwise only this participant's */
    public function byName(string $name, ?int $for = null): ?Project;

    /**
     * @param int|null $for null — the owner: every project; otherwise only this participant's
     * @return list<Project> active first, then by name
     */
    public function all(int $limit, ?int $for = null): array;

    /** Changes what is given; null leaves a field as it is. */
    public function update(int $id, ?string $goal, ?string $status, ?string $card): ?Project;

    /** @param array<string, int> $profile */
    public function addTask(int $projectId, string $title, ?int $parentId, array $profile, ?DateTimeImmutable $dueAt = null): ProjectTask;

    /** Sets the deadline of a task; null removes it. */
    public function setTaskDue(int $id, ?DateTimeImmutable $dueAt): ?ProjectTask;

    /** @return list<ProjectTask> open tasks of active projects with a deadline by $until, earliest first */
    public function dueTasks(DateTimeImmutable $until, int $limit): array;

    public function task(int $id): ?ProjectTask;

    public function setTaskStatus(int $id, string $status, ?string $result): ?ProjectTask;

    /** @return list<ProjectTask> open and in progress, oldest first */
    public function openTasks(int $projectId, int $limit): array;

    public function relation(int $projectId, int $principalId): ?string;

    public function setRelation(int $projectId, int $principalId, string $text): void;

    /** @param string $threadId the forum topic; '' — the chat itself */
    public function active(string $channel, string $chatId, string $threadId = ''): ?Project;

    /** Binds the chat to a project; null unbinds it. */
    public function activate(string $channel, string $chatId, ?int $projectId, string $threadId = ''): void;
}
