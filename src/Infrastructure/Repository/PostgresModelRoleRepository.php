<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Repository\ModelRoleRepository;

final class PostgresModelRoleRepository implements ModelRoleRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function all(): array {
        $roles = [];
        foreach ($this->db->query('SELECT role, provider, model, reason, set_by, set_by_principal FROM model_roles ORDER BY role') as $row) {
            $role = $row['role'] ?? null;
            $provider = $row['provider'] ?? null;
            $model = $row['model'] ?? null;
            $reason = $row['reason'] ?? null;
            $setBy = $row['set_by'] ?? null;
            $principal = $row['set_by_principal'] ?? null;
            if (is_string($role) && is_string($provider) && is_string($model) && is_string($reason) && is_string($setBy)) {
                $roles[$role] = new ModelRole($role, new ModelRef($provider, $model), $reason, $setBy, is_int($principal) ? $principal : null);
            }
        }
        return $roles;
    }

    public function set(string $role, ModelRef $model, string $reason, string $setBy, ?int $principalId = null): bool {
        // One statement: a row the owner fixed is changed only by the owner.
        $row = $this->db->execute(
            "INSERT INTO model_roles (role, provider, model, reason, set_by, set_by_principal) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (role) DO UPDATE SET provider = EXCLUDED.provider, model = EXCLUDED.model, reason = EXCLUDED.reason,
                set_by = EXCLUDED.set_by, set_by_principal = EXCLUDED.set_by_principal, updated_at = now()
             WHERE model_roles.set_by <> 'owner' OR EXCLUDED.set_by = 'owner'
             RETURNING role",
            [$role, $model->provider, $model->model, $reason, $setBy, $principalId],
        )->fetchRow();
        return $row !== null;
    }

    public function seed(array $models, string $reason): void {
        foreach ($models as $role => $model) {
            $this->db->execute(
                "INSERT INTO model_roles (role, provider, model, reason, set_by) VALUES (?, ?, ?, ?, 'seed') ON CONFLICT (role) DO NOTHING",
                [$role, $model->provider, $model->model, $reason],
            );
        }
    }
}
