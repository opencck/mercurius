<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\ProjectRepository;
use UnexpectedValueException;

final class PostgresProjectRepository implements ProjectRepository {
    private const PROJECT = 'id, name, goal, status, card, extract(epoch FROM updated_at)::bigint AS updated';
    private const TASK = 'id, project_id, parent_id, title, status, profile::text AS profile, result';

    public function __construct(private readonly PostgresLink $db) {}

    public function create(string $name, string $goal): Project {
        if ($this->byName($name) !== null) {
            throw new DomainException(sprintf('Проект «%s» уже есть.', $name));
        }
        try {
            $row = $this->db->execute('INSERT INTO projects (name, goal) VALUES (?, ?) RETURNING ' . self::PROJECT, [$name, $goal])->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf('Проект «%s» уже есть.', $name));
        }
        return self::project($row ?? throw new UnexpectedValueException('Project was not created'));
    }

    public function find(int $id): ?Project {
        $row = $this->db->execute('SELECT ' . self::PROJECT . ' FROM projects WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function byName(string $name): ?Project {
        $row = $this->db->execute('SELECT ' . self::PROJECT . ' FROM projects WHERE lower(name) = lower(?)', [$name])->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function all(int $limit): array {
        $found = [];
        foreach ($this->db->execute("SELECT " . self::PROJECT . " FROM projects ORDER BY status <> 'active', lower(name) LIMIT ?", [$limit]) as $row) {
            $found[] = self::project($row);
        }
        return $found;
    }

    public function update(int $id, ?string $goal, ?string $status, ?string $card): ?Project {
        $row = $this->db->execute(
            'UPDATE projects SET goal = coalesce(?, goal), status = coalesce(?, status), card = coalesce(?, card), updated_at = now() WHERE id = ? RETURNING ' . self::PROJECT,
            [$goal, $status, $card, $id],
        )->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function addTask(int $projectId, string $title, ?int $parentId, array $profile): ProjectTask {
        $row = $this->db->execute(
            'INSERT INTO project_tasks (project_id, parent_id, title, profile) VALUES (?, ?, ?, ?::jsonb) RETURNING ' . self::TASK,
            [$projectId, $parentId, $title, $profile === [] ? null : json_encode($profile, JSON_THROW_ON_ERROR)],
        )->fetchRow();
        return self::task_($row ?? throw new UnexpectedValueException('Task was not created'));
    }

    public function task(int $id): ?ProjectTask {
        $row = $this->db->execute('SELECT ' . self::TASK . ' FROM project_tasks WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::task_($row);
    }

    public function setTaskStatus(int $id, string $status, ?string $result): ?ProjectTask {
        $row = $this->db->execute(
            "UPDATE project_tasks SET status = ?, result = coalesce(?, result),
             closed_at = CASE WHEN ? IN ('done', 'dropped') THEN now() ELSE NULL END WHERE id = ? RETURNING " . self::TASK,
            [$status, $result, $status, $id],
        )->fetchRow();
        return $row === null ? null : self::task_($row);
    }

    public function openTasks(int $projectId, int $limit): array {
        $found = [];
        foreach ($this->db->execute(
            "SELECT " . self::TASK . " FROM project_tasks WHERE project_id = ? AND status IN ('open', 'doing') ORDER BY id LIMIT ?",
            [$projectId, $limit],
        ) as $row) {
            $found[] = self::task_($row);
        }
        return $found;
    }

    public function relation(int $projectId, int $principalId): ?string {
        $row = $this->db->execute('SELECT text FROM project_relations WHERE project_id = ? AND principal_id = ?', [$projectId, $principalId])->fetchRow();
        $text = $row['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    public function setRelation(int $projectId, int $principalId, string $text): void {
        $this->db->execute(
            'INSERT INTO project_relations (project_id, principal_id, text) VALUES (?, ?, ?)
             ON CONFLICT (project_id, principal_id) DO UPDATE SET text = excluded.text, updated_at = now()',
            [$projectId, $principalId, $text],
        );
    }

    public function active(string $channel, string $chatId): ?Project {
        $row = $this->db->execute(
            'SELECT p.id, p.name, p.goal, p.status, p.card, extract(epoch FROM p.updated_at)::bigint AS updated
             FROM chat_projects c JOIN projects p ON p.id = c.project_id WHERE c.channel = ? AND c.chat_id = ?',
            [$channel, $chatId],
        )->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function activate(string $channel, string $chatId, ?int $projectId): void {
        if ($projectId === null) {
            $this->db->execute('DELETE FROM chat_projects WHERE channel = ? AND chat_id = ?', [$channel, $chatId]);
            return;
        }
        $this->db->execute(
            'INSERT INTO chat_projects (channel, chat_id, project_id) VALUES (?, ?, ?)
             ON CONFLICT (channel, chat_id) DO UPDATE SET project_id = excluded.project_id, updated_at = now()',
            [$channel, $chatId, $projectId],
        );
    }

    /** @param array<string, mixed> $row */
    private static function project(array $row): Project {
        return new Project(self::int($row, 'id'), self::str($row, 'name'), self::str($row, 'goal'), self::str($row, 'status'), self::str($row, 'card'), self::int($row, 'updated'));
    }

    /** @param array<string, mixed> $row */
    private static function task_(array $row): ProjectTask {
        $profile = [];
        if (isset($row['profile']) && is_string($row['profile'])) {
            /** @var mixed $decoded */
            $decoded = json_decode($row['profile'], true, 4, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach (array_keys($decoded) as $axis) {
                    if (is_string($axis) && isset($decoded[$axis]) && is_int($decoded[$axis])) {
                        $profile[$axis] = $decoded[$axis];
                    }
                }
            }
        }
        return new ProjectTask(
            self::int($row, 'id'),
            self::int($row, 'project_id'),
            isset($row['parent_id']) && is_int($row['parent_id']) ? $row['parent_id'] : null,
            self::str($row, 'title'),
            self::str($row, 'status'),
            $profile,
            isset($row['result']) && is_string($row['result']) ? $row['result'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed project row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed project row: %s', $key));
        }
        return $value;
    }
}
