<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ProjectPolicyRepository;
use UnexpectedValueException;

/** spec: brief/participants.md#права-проекта */
final class PostgresProjectPolicyRepository implements ProjectPolicyRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function find(int $projectId): ?array {
        $row = $this->db->execute('SELECT policy::text AS policy FROM project_policies WHERE project_id = ?', [$projectId])->fetchRow();
        if ($row === null) {
            return null;
        }
        $json = $row['policy'] ?? null;
        /** @var mixed $decoded */
        $decoded = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Malformed project_policies row');
        }
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }

    public function save(int $projectId, array $policy): void {
        $this->db->execute(
            'INSERT INTO project_policies (project_id, policy) VALUES (?, ?::jsonb)
             ON CONFLICT (project_id) DO UPDATE SET policy = EXCLUDED.policy, updated_at = now()',
            [$projectId, json_encode((object) $policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
    }

    public function projects(): array {
        $ids = [];
        foreach ($this->db->execute('SELECT project_id FROM project_policies ORDER BY project_id') as $row) {
            $ids[] = (int) $row['project_id'];
        }
        return $ids;
    }
}
