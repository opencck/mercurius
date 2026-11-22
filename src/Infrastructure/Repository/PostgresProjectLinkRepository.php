<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ProjectLinkRepository;

final class PostgresProjectLinkRepository implements ProjectLinkRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function subscribe(int $projectId, int $principalId): void {
        $this->db->execute('INSERT INTO project_subscriptions (project_id, principal_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$projectId, $principalId]);
    }

    public function subscribers(int $projectId): array {
        $ids = [];
        foreach ($this->db->execute('SELECT principal_id FROM project_subscriptions WHERE project_id = ? ORDER BY principal_id', [$projectId]) as $row) {
            $id = $row['principal_id'] ?? null;
            if (is_int($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function unsubscribe(int $projectId, int $principalId): bool {
        return $this->db->execute('DELETE FROM project_subscriptions WHERE project_id = ? AND principal_id = ? RETURNING project_id', [$projectId, $principalId])->fetchRow() !== null;
    }

    public function subscribed(int $projectId, int $principalId): bool {
        return $this->db->execute('SELECT 1 AS found FROM project_subscriptions WHERE project_id = ? AND principal_id = ?', [$projectId, $principalId])->fetchRow() !== null;
    }

    public function subscriptionsOf(int $principalId): array {
        $found = [];
        foreach ($this->db->execute('SELECT project_id FROM project_subscriptions WHERE principal_id = ? ORDER BY project_id', [$principalId]) as $row) {
            $id = $row['project_id'] ?? null;
            if (is_int($id)) {
                $found[] = $id;
            }
        }
        return $found;
    }
}
