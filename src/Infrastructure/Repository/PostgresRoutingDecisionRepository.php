<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;

final class PostgresRoutingDecisionRepository implements RoutingDecisionRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function openingRoles(array $conversationIds): array {
        if ($conversationIds === []) {
            return [];
        }
        $roles = [];
        foreach ($this->db->execute(
            "SELECT DISTINCT ON (conversation_id) conversation_id, role FROM routing_decisions
             WHERE conversation_id = ANY(?::bigint[]) AND (class IN ('short', 'normal', 'complex') OR method = 'role_change')
             ORDER BY conversation_id, id DESC",
            ['{' . implode(',', $conversationIds) . '}'],
        ) as $row) {
            $id = $row['conversation_id'] ?? null;
            $role = $row['role'] ?? null;
            if (is_int($id) && is_string($role)) {
                $roles[$id] = $role;
            }
        }
        return $roles;
    }

    public function record(RoutingDecision $decision): void {
        $this->db->execute(
            'INSERT INTO routing_decisions (conversation_id, class, method, role, model, reason, run_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$decision->conversationId, $decision->class, $decision->method, $decision->role, $decision->model, $decision->reason, $decision->runId],
        );
    }
}
