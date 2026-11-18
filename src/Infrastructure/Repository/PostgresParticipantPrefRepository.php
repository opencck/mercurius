<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ParticipantPrefRepository;

final class PostgresParticipantPrefRepository implements ParticipantPrefRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function get(int $principalId): array {
        $row = $this->db->execute('SELECT timezone, quiet_hours FROM participant_prefs WHERE principal_id = ?', [$principalId])->fetchRow();
        $zone = $row['timezone'] ?? null;
        $quiet = $row['quiet_hours'] ?? null;
        return ['timezone' => is_string($zone) ? $zone : null, 'quiet' => is_string($quiet) ? $quiet : null];
    }

    public function save(int $principalId, ?string $timezone, ?string $quiet): void {
        $this->db->execute(
            'INSERT INTO participant_prefs (principal_id, timezone, quiet_hours) VALUES (?, ?, ?)
             ON CONFLICT (principal_id) DO UPDATE SET timezone = excluded.timezone, quiet_hours = excluded.quiet_hours, updated_at = now()',
            [$principalId, $timezone, $quiet],
        );
    }
}
