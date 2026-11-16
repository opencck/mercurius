<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\RoutingDecision;

interface RoutingDecisionRepository {
    public function record(RoutingDecision $decision): void;
}
