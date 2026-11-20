<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use OpenCCK\Domain\Agent\ModelRef;

/**
 * A model put into a role, and how many open conversations of the role moved to it.
 *
 * spec: brief/agent-loop.md#канал
 */
final class RoleChange {
    public function __construct(
        public readonly ModelRef $model,
        public readonly int $moved,
    ) {}
}
