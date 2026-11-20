<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DateTimeImmutable;
use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Domain\Repository\ProjectRepository;
use UnexpectedValueException;

use function Mercurius\I18n\t;

final class PostgresProjectRepository implements ProjectRepository {
    private const PROJECT = 'id, name, goal, status, card, extract(epoch FROM updated_at)::bigint AS updated, owner_id, skill';
    private const TASK = 'id, project_id, parent_id, title, status, profile::text AS profile, result, extract(epoch FROM due_at)::bigint AS due';

    public function __construct(private readonly PostgresLink $db) {}

    public function create(string $name, string $goal, ?int $ownerId = null): Project {
        $row = $this->db->execute('SELECT id FROM projects WHERE coalesce(owner_id, 0) = ? AND lower(name) = lower(?)', [$ownerId ?? 0, $name])->fetchRow();
        if ($row !== null) {
            throw new DomainException(sprintf(t('repository.postgres_project.proekt_uzhe_est'), $name));
        }
        try {
            $row = $this->db->execute('INSERT INTO projects (name, goal, owner_id) VALUES (?, ?, ?) RETURNING ' . self::PROJECT, [$name, $goal, $ownerId])->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_project.proekt_uzhe_est'), $name));
        }
        return self::project($row ?? throw new UnexpectedValueException('Project was not created'));
    }

    public function rename(int $id, string $name): ?Project {
        $taken = $this->db->execute(
            'SELECT 1 FROM projects WHERE id <> ? AND lower(name) = lower(?) AND coalesce(owner_id, 0) = (SELECT coalesce(owner_id, 0) FROM projects WHERE id = ?)',
            [$id, $name, $id],
        )->fetchRow();
        if ($taken !== null) {
            throw new DomainException(sprintf(t('repository.postgres_project.proekt_uzhe_est'), $name));
        }
        try {
            $row = $this->db->execute('UPDATE projects SET name = ?, updated_at = now() WHERE id = ? RETURNING ' . self::PROJECT, [$name, $id])->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_project.proekt_uzhe_est'), $name));
        }
        return $row === null ? null : self::project($row);
    }

    public function find(int $id): ?Project {
        $row = $this->db->execute('SELECT ' . self::PROJECT . ' FROM projects WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function byName(string $name, ?int $for = null): ?Project {
        $row = $this->db->execute(
            'SELECT ' . self::PROJECT . ' FROM projects WHERE lower(name) = lower(?) AND (?::bigint IS NULL OR owner_id = ?'
            . ' OR id IN (SELECT project_id FROM project_members WHERE principal_id = ?))'
            . ' ORDER BY CASE WHEN ?::bigint IS NULL THEN owner_id IS NOT NULL ELSE owner_id IS DISTINCT FROM ? END, id LIMIT 1',
            [$name, $for, $for, $for, $for, $for],
        )->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function all(int $limit, ?int $for = null): array {
        $found = [];
        foreach ($this->db->execute("SELECT " . self::PROJECT . " FROM projects WHERE ?::bigint IS NULL OR owner_id = ? OR id IN (SELECT project_id FROM project_members WHERE principal_id = ?) ORDER BY status <> 'active', lower(name) LIMIT ?", [$for, $for, $for, $limit]) as $row) {
            $found[] = self::project($row);
        }
        return $found;
    }

    public function share(int $projectId, int $principalId, string $access, ?int $by): void {
        $this->db->execute(
            'INSERT INTO project_members (project_id, principal_id, access, granted_by) VALUES (?, ?, ?, ?)'
            . ' ON CONFLICT (project_id, principal_id) DO UPDATE SET access = EXCLUDED.access, granted_by = EXCLUDED.granted_by',
            [$projectId, $principalId, $access, $by],
        );
    }

    public function unshare(int $projectId, int $principalId): bool {
        return $this->db->execute('DELETE FROM project_members WHERE project_id = ? AND principal_id = ?', [$projectId, $principalId])->getRowCount() > 0;
    }

    public function memberLevel(int $projectId, int $principalId): ?string {
        $row = $this->db->execute('SELECT access FROM project_members WHERE project_id = ? AND principal_id = ?', [$projectId, $principalId])->fetchRow();
        return $row === null ? null : self::str($row, 'access');
    }

    public function members(int $projectId): array {
        $found = [];
        foreach ($this->db->execute('SELECT principal_id, access FROM project_members WHERE project_id = ? ORDER BY created_at, principal_id', [$projectId]) as $row) {
            $found[] = ['principalId' => self::int($row, 'principal_id'), 'access' => self::str($row, 'access')];
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

    public function addTask(int $projectId, string $title, ?int $parentId, array $profile, ?DateTimeImmutable $dueAt = null): ProjectTask {
        $row = $this->db->execute(
            'INSERT INTO project_tasks (project_id, parent_id, title, profile, due_at) VALUES (?, ?, ?, ?::jsonb, to_timestamp(?)) RETURNING ' . self::TASK,
            [$projectId, $parentId, $title, $profile === [] ? null : json_encode($profile, JSON_THROW_ON_ERROR), $dueAt?->getTimestamp()],
        )->fetchRow();
        return self::task_($row ?? throw new UnexpectedValueException('Task was not created'));
    }

    public function setTaskDue(int $id, ?DateTimeImmutable $dueAt): ?ProjectTask {
        $row = $this->db->execute('UPDATE project_tasks SET due_at = to_timestamp(?) WHERE id = ? RETURNING ' . self::TASK, [$dueAt?->getTimestamp(), $id])->fetchRow();
        return $row === null ? null : self::task_($row);
    }

    public function dueTasks(DateTimeImmutable $until, int $limit): array {
        $found = [];
        foreach ($this->db->execute(
            "SELECT " . self::TASK . " FROM project_tasks
             WHERE status IN ('open', 'doing') AND due_at <= to_timestamp(?)
               AND project_id IN (SELECT id FROM projects WHERE status = 'active' AND owner_id IS NULL)
             ORDER BY due_at, id LIMIT ?",
            [$until->getTimestamp(), $limit],
        ) as $row) {
            $found[] = self::task_($row);
        }
        return $found;
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

    public function active(string $channel, string $chatId, string $threadId = ''): ?Project {
        // owner_id too: without it a participant's own project looked like the owner's and was hidden from them
        $row = $this->db->execute(
            'SELECT p.id, p.name, p.goal, p.status, p.card, extract(epoch FROM p.updated_at)::bigint AS updated, p.owner_id, p.skill
             FROM chat_projects c JOIN projects p ON p.id = c.project_id WHERE c.channel = ? AND c.chat_id = ? AND c.thread_id = ?',
            [$channel, $chatId, $threadId],
        )->fetchRow();
        return $row === null ? null : self::project($row);
    }

    public function activate(string $channel, string $chatId, ?int $projectId, string $threadId = ''): void {
        if ($projectId === null) {
            $this->db->execute('DELETE FROM chat_projects WHERE channel = ? AND chat_id = ? AND thread_id = ?', [$channel, $chatId, $threadId]);
            return;
        }
        $this->db->execute(
            'INSERT INTO chat_projects (channel, chat_id, thread_id, project_id) VALUES (?, ?, ?, ?)
             ON CONFLICT (channel, chat_id, thread_id) DO UPDATE SET project_id = excluded.project_id, updated_at = now()',
            [$channel, $chatId, $threadId, $projectId],
        );
    }

    public function topicsOf(int $projectId): array {
        $found = [];
        foreach ($this->db->execute("SELECT channel, chat_id, thread_id FROM chat_projects WHERE project_id = ? AND thread_id <> '' ORDER BY updated_at", [$projectId]) as $row) {
            $found[] = ['channel' => self::str($row, 'channel'), 'chatId' => self::str($row, 'chat_id'), 'threadId' => self::str($row, 'thread_id')];
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function project(array $row): Project {
        return new Project(self::int($row, 'id'), self::str($row, 'name'), self::str($row, 'goal'), self::str($row, 'status'), self::str($row, 'card'), self::int($row, 'updated'), isset($row['owner_id']) && is_int($row['owner_id']) ? $row['owner_id'] : null, self::str($row, 'skill'));
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
            isset($row['due']) && is_int($row['due']) ? new DateTimeImmutable('@' . $row['due']) : null,
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
