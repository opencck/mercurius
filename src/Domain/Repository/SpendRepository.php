<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * What model calls cost: the base of the spend caps.
 *
 * spec: brief/agent-loop.md#лимиты — caps in money over all runs
 */
interface SpendRepository {
    /** USD of all model calls since the moment: runs, workers, background work, compaction. */
    public function spentSince(DateTimeImmutable $since): float;

    /** USD of the model calls in the conversations of one participant since the moment. */
    public function spentBy(int $principalId, DateTimeImmutable $since): float;
}
