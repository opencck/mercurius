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

    public function find(int $id): ?Project {
        return $this->projects[$id] ?? null;
    }

    public function byName(string $name, ?int $for = null): ?Project {
        $found = array_values(array_filter($this->projects, static fn(Project $p): bool => mb_strtolower($p->name) === mb_strtolower($name) && ($for === null || $p->ownerId === $for)));
        usort($found, static fn(Project $a, Project $b): int => [$a->ownerId !== null, $a->id] <=> [$b->ownerId !== null, $b->id]);
        return $found[0] ?? null;
    }

    public function all(int $limit, ?int $for = null): array {
        $all = array_values(array_filter($this->projects, static fn(Project $p): bool => $for === null || $p->ownerId === $for));
        usort($all, static fn(Project $a, Project $b): int => [$a->status !== Project::ACTIVE, mb_strtolower($a->name)] <=> [$b->status !== Project::ACTIVE, mb_strtolower($b->name)]);
        return array_slice($all, 0, $limit);
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
