<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;

final class PostgresRoutingDecisionRepository implements RoutingDecisionRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function record(RoutingDecision $decision): void {
        $this->db->execute(
            'INSERT INTO routing_decisions (conversation_id, class, method, role, model, reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$decision->conversationId, $decision->class, $decision->method, $decision->role, $decision->model, $decision->reason],
        );
    }
}
