<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/** A saved permission: this participant's action with this key no longer asks. */
final class Grant {
    public function __construct(
        public readonly int $id,
        public readonly int $principalId,
        public readonly string $key,
    ) {}
}
