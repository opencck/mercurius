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

    public function create(string $name, string $goal): Project {
        if ($this->byName($name) !== null) {
            throw new DomainException(sprintf('Проект «%s» уже есть.', $name));
        }
        $id = count($this->projects) + 1;
        return $this->projects[$id] = new Project($id, $name, $goal, Project::ACTIVE, '', time());
    }

    public function find(int $id): ?Project {
        return $this->projects[$id] ?? null;
    }

    public function byName(string $name): ?Project {
        foreach ($this->projects as $project) {
            if (mb_strtolower($project->name) === mb_strtolower($name)) {
                return $project;
            }
        }
        return null;
    }

    public function all(int $limit): array {
        $all = array_values($this->projects);
        usort($all, static fn(Project $a, Project $b): int => [$a->status !== Project::ACTIVE, mb_strtolower($a->name)] <=> [$b->status !== Project::ACTIVE, mb_strtolower($b->name)]);
        return array_slice($all, 0, $limit);
    }

    public function update(int $id, ?string $goal, ?string $status, ?string $card): ?Project {
        $p = $this->projects[$id] ?? null;
        if ($p === null) {
            return null;
        }
        return $this->projects[$id] = new Project($p->id, $p->name, $goal ?? $p->goal, $status ?? $p->status, $card ?? $p->card, time());
    }

    public function addTask(int $projectId, string $title, ?int $parentId, array $profile): ProjectTask {
        $id = count($this->tasks) + 1;
        return $this->tasks[$id] = new ProjectTask($id, $projectId, $parentId, $title, ProjectTask::OPEN, $profile, null);
    }

    public function task(int $id): ?ProjectTask {
        return $this->tasks[$id] ?? null;
    }

    public function setTaskStatus(int $id, string $status, ?string $result): ?ProjectTask {
        $t = $this->tasks[$id] ?? null;
        if ($t === null) {
            return null;
        }
        return $this->tasks[$id] = new ProjectTask($t->id, $t->projectId, $t->parentId, $t->title, $status, $t->profile, $result ?? $t->result);
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

    public function active(string $channel, string $chatId): ?Project {
        $id = $this->chats[$channel . ':' . $chatId] ?? null;
        return $id === null ? null : ($this->projects[$id] ?? null);
    }

    public function activate(string $channel, string $chatId, ?int $projectId): void {
        if ($projectId === null) {
            unset($this->chats[$channel . ':' . $chatId]);
            return;
        }
        $this->chats[$channel . ':' . $chatId] = $projectId;
    }
}
