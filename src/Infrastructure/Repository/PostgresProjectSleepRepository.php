<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ProjectSleepRepository;

final class PostgresProjectSleepRepository implements ProjectSleepRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function on(int $projectId): bool {
        return $this->db->execute('SELECT 1 FROM project_sleep WHERE project_id = ?', [$projectId])->fetchRow() !== null;
    }

    public function set(int $projectId, bool $on): void {
        $this->db->execute(
            $on ? 'INSERT INTO project_sleep (project_id) VALUES (?) ON CONFLICT (project_id) DO NOTHING' : 'DELETE FROM project_sleep WHERE project_id = ?',
            [$projectId],
        );
    }

    public function sleeping(): array {
        $ids = [];
        foreach ($this->db->query('SELECT project_id FROM project_sleep ORDER BY project_id') as $row) {
            if (isset($row['project_id']) && is_int($row['project_id'])) {
                $ids[] = $row['project_id'];
            }
        }
        return $ids;
    }
}
