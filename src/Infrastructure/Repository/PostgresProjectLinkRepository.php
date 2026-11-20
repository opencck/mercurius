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

    public function bindSecret(int $secretId, int $projectId, string $host): void {
        $this->db->execute(
            'INSERT INTO project_secrets (secret_id, project_id, host) VALUES (?, ?, ?) ON CONFLICT (secret_id) DO UPDATE SET project_id = EXCLUDED.project_id, host = EXCLUDED.host',
            [$secretId, $projectId, $host],
        );
    }

    public function unbindSecret(int $secretId): bool {
        return $this->db->execute('DELETE FROM project_secrets WHERE secret_id = ? RETURNING secret_id', [$secretId])->fetchRow() !== null;
    }

    public function secretBinding(int $secretId): ?array {
        $row = $this->db->execute('SELECT project_id, host FROM project_secrets WHERE secret_id = ?', [$secretId])->fetchRow();
        $project = $row['project_id'] ?? null;
        $host = $row['host'] ?? null;
        return is_int($project) && is_string($host) ? ['projectId' => $project, 'host' => $host] : null;
    }

    public function secretsOf(int $projectId): array {
        $found = [];
        foreach ($this->db->execute('SELECT secret_id, host FROM project_secrets WHERE project_id = ? ORDER BY secret_id', [$projectId]) as $row) {
            $secret = $row['secret_id'] ?? null;
            $host = $row['host'] ?? null;
            if (is_int($secret) && is_string($host)) {
                $found[] = ['secretId' => $secret, 'host' => $host];
            }
        }
        return $found;
    }
}
