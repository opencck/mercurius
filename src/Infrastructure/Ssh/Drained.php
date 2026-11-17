<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Ssh;

/** A stream read to its end: what was kept within the limit, and whether some was dropped. */
final class Drained {
    public function __construct(
        public readonly string $kept,
        public readonly bool $cut,
    ) {}
}
