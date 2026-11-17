<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;

/**
 * spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — projects, their tasks, each participant's relation
 *       to a project, and the project a chat works on
 */
interface ProjectRepository {
    /** @throws DomainException when a project with this name exists (names are compared case-insensitively) */
    public function create(string $name, string $goal): Project;

    public function find(int $id): ?Project;

    public function byName(string $name): ?Project;

    /** @return list<Project> active first, then by name */
    public function all(int $limit): array;

    /** Changes what is given; null leaves a field as it is. */
    public function update(int $id, ?string $goal, ?string $status, ?string $card): ?Project;

    /** @param array<string, int> $profile */
    public function addTask(int $projectId, string $title, ?int $parentId, array $profile): ProjectTask;

    public function task(int $id): ?ProjectTask;

    public function setTaskStatus(int $id, string $status, ?string $result): ?ProjectTask;

    /** @return list<ProjectTask> open and in progress, oldest first */
    public function openTasks(int $projectId, int $limit): array;

    public function relation(int $projectId, int $principalId): ?string;

    public function setRelation(int $projectId, int $principalId, string $text): void;

    public function active(string $channel, string $chatId): ?Project;

    /** Binds the chat to a project; null unbinds it. */
    public function activate(string $channel, string $chatId, ?int $projectId): void;
}
