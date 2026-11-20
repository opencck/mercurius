<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\ProjectRepository;

final class InMemoryProjectRepository implements ProjectRepository {
    /** @var array<int, Project> */
    public array $projects = [];
    /** @var array<int, ProjectTask> */
    public array $tasks = [];
    /** @var array<string, string> "project:principal" => text */
    public array $relations = [];
    /** @var array<string, int> "channel:chat" => project */
    public array $chats = [];
    /** @var array<string, array{channel: string, chatId: string, threadId: string}> */
    private array $places = [];

    public function create(string $name, string $goal, ?int $ownerId = null): Project {
        foreach ($this->projects as $p) {
            if ($p->ownerId === $ownerId && mb_strtolower($p->name) === mb_strtolower($name)) {
                throw new DomainException(sprintf('Проект «%s» уже есть.', $name));
            }
        }
        $id = count($this->projects) + 1;
        return $this->projects[$id] = new Project($id, $name, $goal, Project::ACTIVE, '', time(), $ownerId);
    }

    /** @var array<int, \OpenCCK\Domain\Project\ProjectTransfer> */
    private array $transfers = [];

    public function setOwner(int $projectId, ?int $ownerId): ?Project {
        $p = $this->projects[$projectId] ?? null;
        if ($p === null) {
            return null;
        }
        foreach ($this->projects as $other) {
            if ($other->id !== $projectId && $other->ownerId === $ownerId && mb_strtolower($other->name) === mb_strtolower($p->name)) {
                throw new DomainException(sprintf('Проект «%s» уже есть.', $p->name));
            }
        }
        if ($ownerId !== null) {
            unset($this->members[$projectId][$ownerId]);
        }
        return $this->projects[$projectId] = new Project($p->id, $p->name, $p->goal, $p->status, $p->card, time(), $ownerId, $p->skill);
    }

    public function isEmpty(int $projectId): bool {
        $p = $this->projects[$projectId] ?? null;
        $tasks = array_filter($this->tasks, static fn(ProjectTask $t): bool => $t->projectId === $projectId);
        return $p !== null && $p->goal === '' && $p->card === '' && $p->skill === '' && $tasks === [];
    }

    public function absorb(int $projectId, int $emptyId): void {
        foreach ($this->chats as $key => $id) {
            if ($id === $emptyId) {
                $this->chats[$key] = $projectId;
            }
        }
        unset($this->projects[$emptyId], $this->members[$emptyId]);
    }

    public function offer(int $projectId, ?int $fromId, ?int $toId): \OpenCCK\Domain\Project\ProjectTransfer {
        foreach ($this->transfers as $t) {
            if ($t->projectId === $projectId && $t->status === \OpenCCK\Domain\Project\ProjectTransfer::PENDING) {
                $this->settle($t->id, \OpenCCK\Domain\Project\ProjectTransfer::CANCELLED);
            }
        }
        $id = count($this->transfers) + 1;
        return $this->transfers[$id] = new \OpenCCK\Domain\Project\ProjectTransfer($id, $projectId, $fromId, $toId, \OpenCCK\Domain\Project\ProjectTransfer::PENDING);
    }

    public function transfer(int $id): ?\OpenCCK\Domain\Project\ProjectTransfer {
        return $this->transfers[$id] ?? null;
    }

    public function offersTo(?int $toId): array {
        return array_values(array_filter($this->transfers, static fn(\OpenCCK\Domain\Project\ProjectTransfer $t): bool => $t->status === \OpenCCK\Domain\Project\ProjectTransfer::PENDING && $t->toId === $toId));
    }

    public function settle(int $transferId, string $status): bool {
        $t = $this->transfers[$transferId] ?? null;
        if ($t === null || $t->status !== \OpenCCK\Domain\Project\ProjectTransfer::PENDING) {
            return false;
        }
        $this->transfers[$transferId] = new \OpenCCK\Domain\Project\ProjectTransfer($t->id, $t->projectId, $t->fromId, $t->toId, $status);
        return true;
    }

    /** @var array<int, array<int, string>> project => participant => access */
    private array $members = [];

    public function share(int $projectId, int $principalId, string $access, ?int $by): void {
        $this->members[$projectId][$principalId] = $access;
    }

    public function unshare(int $projectId, int $principalId): bool {
        $had = isset($this->members[$projectId][$principalId]);
        unset($this->members[$projectId][$principalId]);
        return $had;
    }

    public function memberLevel(int $projectId, int $principalId): ?string {
        return $this->members[$projectId][$principalId] ?? null;
    }

    public function members(int $projectId): array {
        $found = [];
        foreach ($this->members[$projectId] ?? [] as $principalId => $access) {
            $found[] = ['principalId' => $principalId, 'access' => $access];
        }
        return $found;
    }

    public function find(int $id): ?Project {
        return $this->projects[$id] ?? null;
    }

    public function byName(string $name, ?int $for = null): ?Project {
        $found = array_values(array_filter($this->projects, fn(Project $p): bool => mb_strtolower($p->name) === mb_strtolower($name) && ($for === null || $p->ownerId === $for || isset($this->members[$p->id][$for]))));
        usort($found, static fn(Project $a, Project $b): int => [$for === null ? $a->ownerId !== null : $a->ownerId !== $for, $a->id] <=> [$for === null ? $b->ownerId !== null : $b->ownerId !== $for, $b->id]);
        return $found[0] ?? null;
    }

    public function all(int $limit, ?int $for = null): array {
        $all = array_values(array_filter($this->projects, fn(Project $p): bool => $for === null || $p->ownerId === $for || isset($this->members[$p->id][$for])));
        usort($all, static fn(Project $a, Project $b): int => [$a->status !== Project::ACTIVE, mb_strtolower($a->name)] <=> [$b->status !== Project::ACTIVE, mb_strtolower($b->name)]);
        return array_slice($all, 0, $limit);
    }

    public function rename(int $id, string $name): ?Project {
        $p = $this->projects[$id] ?? null;
        if ($p === null) {
            return null;
        }
        foreach ($this->projects as $other) {
            if ($other->id !== $id && $other->ownerId === $p->ownerId && mb_strtolower($other->name) === mb_strtolower($name)) {
                throw new DomainException(sprintf('Проект «%s» уже есть.', $name));
            }
        }
        return $this->projects[$id] = new Project($p->id, $name, $p->goal, $p->status, $p->card, time(), $p->ownerId, $p->skill);
    }

    public function update(int $id, ?string $goal, ?string $status, ?string $card): ?Project {
        $p = $this->projects[$id] ?? null;
        if ($p === null) {
            return null;
        }
        return $this->projects[$id] = new Project($p->id, $p->name, $goal ?? $p->goal, $status ?? $p->status, $card ?? $p->card, time(), $p->ownerId, $p->skill);
    }

    public function addTask(int $projectId, string $title, ?int $parentId, array $profile, ?\DateTimeImmutable $dueAt = null): ProjectTask {
        $id = count($this->tasks) + 1;
        return $this->tasks[$id] = new ProjectTask($id, $projectId, $parentId, $title, ProjectTask::OPEN, $profile, null, $dueAt);
    }

    public function setTaskDue(int $id, ?\DateTimeImmutable $dueAt): ?ProjectTask {
        $t = $this->tasks[$id] ?? null;
        if ($t === null) {
            return null;
        }
        return $this->tasks[$id] = new ProjectTask($t->id, $t->projectId, $t->parentId, $t->title, $t->status, $t->profile, $t->result, $dueAt);
    }

    public function dueTasks(\DateTimeImmutable $until, int $limit): array {
        $found = array_values(array_filter($this->tasks, function (ProjectTask $t) use ($until): bool {
            $project = $this->projects[$t->projectId] ?? null;
            return $t->isOpen() && $t->dueAt !== null && $t->dueAt <= $until && $project !== null && $project->status === Project::ACTIVE && $project->ownerId === null;
        }));
        usort($found, static fn(ProjectTask $a, ProjectTask $b): int => [$a->dueAt, $a->id] <=> [$b->dueAt, $b->id]);
        return array_slice($found, 0, $limit);
    }

    public function task(int $id): ?ProjectTask {
        return $this->tasks[$id] ?? null;
    }

    public function setTaskStatus(int $id, string $status, ?string $result): ?ProjectTask {
        $t = $this->tasks[$id] ?? null;
        if ($t === null) {
            return null;
        }
        return $this->tasks[$id] = new ProjectTask($t->id, $t->projectId, $t->parentId, $t->title, $status, $t->profile, $result ?? $t->result, $t->dueAt);
    }

    public function openTasks(int $projectId, int $limit): array {
        return array_slice(array_values(array_filter($this->tasks, static fn(ProjectTask $t): bool => $t->projectId === $projectId && $t->isOpen())), 0, $limit);
    }

    public function relation(int $projectId, int $principalId): ?string {
        return $this->relations[$projectId . ':' . $principalId] ?? null;
    }

    public function setRelation(int $projectId, int $principalId, string $text): void {
        $this->relations[$projectId . ':' . $principalId] = $text;
    }

    public function active(string $channel, string $chatId, string $threadId = ''): ?Project {
        $id = $this->chats[$channel . ':' . $chatId . ':' . $threadId] ?? null;
        return $id === null ? null : ($this->projects[$id] ?? null);
    }

    public function topicsOf(int $projectId): array {
        $found = [];
        foreach ($this->chats as $key => $id) {
            $place = $this->places[$key] ?? null;
            if ($id === $projectId && $place !== null && $place['threadId'] !== '') {
                $found[] = $place;
            }
        }
        return $found;
    }

    public function activate(string $channel, string $chatId, ?int $projectId, string $threadId = ''): void {
        if ($projectId === null) {
            unset($this->chats[$channel . ':' . $chatId . ':' . $threadId]);
            return;
        }
        $this->chats[$channel . ':' . $chatId . ':' . $threadId] = $projectId;
        $this->places[$channel . ':' . $chatId . ':' . $threadId] = ['channel' => $channel, 'chatId' => $chatId, 'threadId' => $threadId];
    }
}
