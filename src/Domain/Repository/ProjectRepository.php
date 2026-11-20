<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;

/**
 * spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту — projects, their tasks, each participant's relation
 *       to a project, and the project a chat works on
 */
interface ProjectRepository {
    /**
     * @param int|null $ownerId null — the agent's owner; otherwise the participant
     * @throws DomainException when this owner has a project with this name (compared case-insensitively)
     */
    public function create(string $name, string $goal, ?int $ownerId = null): Project;

    public function find(int $id): ?Project;

    /**
     * @param int|null $for null — the owner: every project, their own first; otherwise this participant's own first,
     *        then those shared with them
     */
    public function byName(string $name, ?int $for = null): ?Project;

    /**
     * @param int|null $for null — the owner: every project; otherwise this participant's and those shared with them
     * @return list<Project> active first, then by name
     */
    public function all(int $limit, ?int $for = null): array;

    /**
     * Gives a participant access to a project; a second call changes the level.
     *
     * spec: brief/participants.md#доступ-к-проектам-и-передача
     * @param 'read'|'write'|'full' $access
     * @param int|null $by who gave it; null — the owner of the agent
     */
    public function share(int $projectId, int $principalId, string $access, ?int $by): void;

    /** Takes the access back; false when there was none. */
    public function unshare(int $projectId, int $principalId): bool;

    /** The level a participant was given in the project; null — none. The owner of a project is not its member. */
    public function memberLevel(int $projectId, int $principalId): ?string;

    /** @return list<array{principalId: int, access: string}> by the time the access was given */
    public function members(int $projectId): array;

    /**
     * A new owner of a project; whatever access the new owner had as a member is dropped.
     *
     * @param int|null $ownerId null — the owner of the agent
     * @throws DomainException the new owner has a project of that name
     */
    public function setOwner(int $projectId, ?int $ownerId): ?Project;

    /** Whether a project holds nothing yet: no goal, card, skill or task. */
    public function isEmpty(int $projectId): bool;

    /**
     * Takes the place of an empty project: its chats and forum topics become those of $projectId, and it is removed.
     *
     * spec: brief/participants.md#доступ-к-проектам-и-передача — a project handed over takes the place of an empty one of that name
     */
    public function absorb(int $projectId, int $emptyId): void;

    /**
     * Offers a project to a participant; an earlier offer of the same project is cancelled.
     *
     * @param int|null $fromId null — the owner of the agent
     * @param int|null $toId null — the owner of the agent
     */
    public function offer(int $projectId, ?int $fromId, ?int $toId): \OpenCCK\Domain\Project\ProjectTransfer;

    public function transfer(int $id): ?\OpenCCK\Domain\Project\ProjectTransfer;

    /**
     * @param int|null $toId null — the owner of the agent
     * @return list<\OpenCCK\Domain\Project\ProjectTransfer> the offers waiting for this participant, oldest first
     */
    public function offersTo(?int $toId): array;

    /** Settles a waiting offer; false when it was settled before. */
    public function settle(int $transferId, string $status): bool;

    /** Changes what is given; null leaves a field as it is. */
    public function update(int $id, ?string $goal, ?string $status, ?string $card): ?Project;

    /**
     * A new name of a project; null when there is no such project.
     *
     * spec: brief/channels.md#форум-темы--проекты — a project is renamed together with its forum topics
     * @throws \DomainException another project of the same owner has that name
     */
    public function rename(int $id, string $name): ?Project;


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

    /**
     * The forum topics the project is bound to.
     *
     * @return list<array{channel: string, chatId: string, threadId: string}>
     */
    public function topicsOf(int $projectId): array;
}
