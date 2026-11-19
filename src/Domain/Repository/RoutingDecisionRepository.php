<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\RoutingDecision;

interface RoutingDecisionRepository {
    public function record(RoutingDecision $decision): void;

    /**
     * The role each conversation got when it opened: the latest decision of the router or a move to the new model of
     * a role. The choice of a worker by its profile and the switch after a failure are not the role of a conversation.
     *
     * @param list<int> $conversationIds
     * @return array<int, string> conversation => role, only those with a record
     */
    public function openingRoles(array $conversationIds): array;
}
