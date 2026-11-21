<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ProjectPrefRepository;

/** spec: brief/scheduler.md#часовой-пояс-проекта */
final class PostgresProjectPrefRepository implements ProjectPrefRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function get(int $projectId): array {
        $row = $this->db->execute('SELECT timezone, quiet FROM project_prefs WHERE project_id = ?', [$projectId])->fetchRow();
        $zone = $row['timezone'] ?? null;
        $quiet = $row['quiet'] ?? null;
        return ['timezone' => is_string($zone) ? $zone : null, 'quiet' => is_string($quiet) ? $quiet : null];
    }

    public function save(int $projectId, ?string $timezone, ?string $quiet): void {
        $this->db->execute(
            'INSERT INTO project_prefs (project_id, timezone, quiet) VALUES (?, ?, ?)
             ON CONFLICT (project_id) DO UPDATE SET timezone = EXCLUDED.timezone, quiet = EXCLUDED.quiet, updated_at = now()',
            [$projectId, $timezone, $quiet],
        );
    }
}
